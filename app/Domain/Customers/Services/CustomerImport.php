<?php

declare(strict_types=1);

namespace App\Domain\Customers\Services;

use App\Domain\Compliance\Services\AuditLogger;
use App\Domain\Customers\Exceptions\CustomerActionRefusedException;
use App\Domain\Customers\Models\CustomerGroup;
use App\Domain\Customers\Models\CustomerSource;
use App\Domain\Customers\Models\CustomerTag;
use App\Domain\Identity\Models\User;
use App\Domain\Orders\Models\Customer;
use App\Domain\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module 10 §47–49: customer import — upload, parse, validate, preview,
 * confirm, process, report.
 *
 * - The file is read once and never stored. The rows that may be
 *   imported are kept encrypted in the cache for 30 minutes, for this
 *   store and this staff member only, until they confirm (§49 secure
 *   storage, limited retention, tenant isolation).
 * - Nothing is written before the confirmation. At confirmation every
 *   row is checked again (another import, or a registration, may have
 *   taken an email in between).
 * - An email that already belongs to a customer is skipped, never
 *   merged (§57: no automatic merging).
 * - The audit records the counts, not the people.
 */
final class CustomerImport
{
    public const MAX_ROWS = 2000;
    public const MAX_KILOBYTES = 2048;
    private const TTL_MINUTES = 30;
    private const PREVIEW_ROWS = 200;
    private const COLUMNS = ['name', 'email', 'phone', 'group', 'tags'];

    public function __construct(
        private readonly CustomerManagement $customers,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Reads and checks the file. Returns the preview; the importable rows
     * wait in the cache under the returned id.
     *
     * @return array{id: string, totals: array{rows: int, ready: int, existing: int, invalid: int}, rows: list<array{row: int, name: string, email: string, status: string, messages: list<string>}>, columns: list<string>}
     */
    public function preview(UploadedFile $file, User $actor): array
    {
        $records = $this->read($file);
        $groups = CustomerGroup::query()->get()->keyBy(fn (CustomerGroup $g) => mb_strtolower($g->name));
        $existing = Customer::query()->whereNull('erased_at')->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->flip();

        $seen = [];
        $ready = [];
        $report = [];
        $totals = ['rows' => count($records), 'ready' => 0, 'existing' => 0, 'invalid' => 0];

        foreach ($records as $index => $record) {
            $line = $index + 2; // row 1 is the header
            $name = trim((string) ($record['name'] ?? ''));
            $email = mb_strtolower(trim((string) ($record['email'] ?? '')));
            $phone = CustomerManagement::phone($record['phone'] ?? null);
            $groupName = trim((string) ($record['group'] ?? ''));
            $tags = array_values(array_filter(array_map(fn ($t) => trim($t), preg_split('/[;|]/', (string) ($record['tags'] ?? '')) ?: []), fn ($t) => $t !== ''));
            $messages = [];

            if ($name === '' || mb_strlen($name) > 255) {
                $messages[] = $name === '' ? 'Name is missing.' : 'Name is longer than 255 characters.';
            }
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 255) {
                $messages[] = 'Email is missing or not an email address.';
            }
            if ($phone !== null && (mb_strlen($phone) > 32 || preg_match('/^[0-9+()\-\s.]+$/', $phone) !== 1)) {
                $messages[] = 'Phone has characters a phone number cannot have, or is longer than 32.';
            }
            if ($groupName !== '' && ! $groups->has(mb_strtolower($groupName))) {
                $messages[] = "There is no customer group called \"{$groupName}\". Create it first or leave the group empty.";
            }
            if (count($tags) > CustomerManagement::MAX_TAGS_PER_CUSTOMER) {
                $messages[] = 'More than '.CustomerManagement::MAX_TAGS_PER_CUSTOMER.' tags.';
            }
            foreach ($tags as $tag) {
                if (mb_strlen($tag) > 60) {
                    $messages[] = "The tag \"".mb_substr($tag, 0, 20)."…\" is longer than 60 characters.";
                }
            }

            $status = 'ready';
            if ($messages !== []) {
                $status = 'invalid';
            } elseif ($existing->has($email)) {
                $status = 'existing';
                $messages[] = 'A customer with this email already exists. The row is skipped.';
            } elseif (isset($seen[$email])) {
                $status = 'invalid';
                $messages[] = "The same email is already on row {$seen[$email]}.";
            }

            if ($email !== '' && ! isset($seen[$email])) {
                $seen[$email] = $line;
            }

            $totals[$status === 'ready' ? 'ready' : ($status === 'existing' ? 'existing' : 'invalid')]++;
            if ($status === 'ready') {
                $ready[] = ['row' => $line, 'name' => $name, 'email' => $email, 'phone' => $phone, 'group' => $groupName === '' ? null : $groupName, 'tags' => $tags];
            }
            if (count($report) < self::PREVIEW_ROWS || $status !== 'ready') {
                $report[] = ['row' => $line, 'name' => $name, 'email' => $email, 'status' => $status, 'messages' => $messages];
            }
        }

        $id = (string) Str::ulid();
        Cache::put($this->cacheKey($id, $actor), Crypt::encryptString((string) json_encode($ready)), now()->addMinutes(self::TTL_MINUTES));

        return ['id' => $id, 'totals' => $totals, 'rows' => array_slice($report, 0, self::PREVIEW_ROWS + 100), 'columns' => self::COLUMNS];
    }

    /**
     * Creates the customers of a previewed file.
     *
     * @return array{created: int, skipped: int}
     */
    public function confirm(string $id, User $actor): array
    {
        $key = $this->cacheKey($id, $actor);
        $payload = Cache::pull($key);
        if (! is_string($payload)) {
            throw new CustomerActionRefusedException('This import has expired or was already done. Upload the file again.', 'import_expired', 410);
        }

        /** @var list<array{row: int, name: string, email: string, phone: ?string, group: ?string, tags: list<string>}> $rows */
        $rows = json_decode(Crypt::decryptString($payload), true) ?: [];
        $groups = CustomerGroup::query()->get()->keyBy(fn (CustomerGroup $g) => mb_strtolower($g->name));
        $created = 0;
        $skipped = 0;

        DB::transaction(function () use ($rows, $groups, &$created, &$skipped) {
            $tagIds = [];
            foreach ($rows as $row) {
                // Taken since the preview: skipped, as in the preview.
                if (Customer::query()->whereRaw('LOWER(email) = ?', [$row['email']])->whereNull('erased_at')->exists()) {
                    $skipped++;

                    continue;
                }

                $customer = new Customer(['name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone']]);
                $customer->forceFill([
                    'status' => 'active',
                    'source' => CustomerSource::Import,
                    'customer_group_id' => $row['group'] !== null ? $groups->get(mb_strtolower($row['group']))?->id : null,
                ])->save();

                $ids = [];
                foreach ($row['tags'] as $name) {
                    $normalized = CustomerTag::normalize($name);
                    $ids[] = $tagIds[$normalized] ??= $this->customers->tag($name)->id;
                }
                if ($ids !== []) {
                    $customer->tags()->syncWithPivotValues(array_values(array_unique($ids)), ['store_id' => $customer->store_id, 'created_at' => now()]);
                }
                $created++;
            }
        });

        $this->audit->record('customers.imported', ['created' => $created, 'skipped' => $skipped], actor: $actor);

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * The rows of the file as column => value, by the header row.
     *
     * @return list<array<string, string>>
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'rb');
        if ($handle === false) {
            throw new CustomerActionRefusedException('The file could not be read.', 'unreadable', 422);
        }

        try {
            $first = fgets($handle);
            if ($first === false) {
                throw new CustomerActionRefusedException('The file is empty.', 'empty', 422);
            }
            // A spreadsheet saved as CSV may use ; and may start with a byte-order mark.
            $first = (string) preg_replace('/^\xEF\xBB\xBF/', '', $first);
            $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
            $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), str_getcsv($first, $delimiter));

            if (! in_array('name', $header, true) || ! in_array('email', $header, true)) {
                throw new CustomerActionRefusedException('The first row must name the columns, with at least "name" and "email". Allowed: '.implode(', ', self::COLUMNS).'.', 'bad_header', 422);
            }

            $records = [];
            while (($cells = fgetcsv($handle, 0, $delimiter)) !== false) {
                if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
                    continue;
                }
                if (count($records) >= self::MAX_ROWS) {
                    throw new CustomerActionRefusedException('The file has more than '.self::MAX_ROWS.' customers. Split it into smaller files.', 'too_many_rows', 422);
                }
                $record = [];
                foreach ($header as $position => $column) {
                    if (in_array($column, self::COLUMNS, true)) {
                        $record[$column] = (string) ($cells[$position] ?? '');
                    }
                }
                $records[] = $record;
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    private function cacheKey(string $id, User $actor): string
    {
        return "customer-import:{$this->context->storeId()}:{$actor->id}:{$id}";
    }
}
