<?php

declare(strict_types=1);

namespace App\Domain\Pwa\Console;

use Illuminate\Console\Command;

/**
 * Phase B55 (Module 25 §52, §54) — makes the platform's VAPID key pair for
 * web push. The keys are printed once for the operator to put in the
 * server environment (PWA_VAPID_PUBLIC_KEY, PWA_VAPID_PRIVATE_KEY,
 * PWA_VAPID_SUBJECT). Nothing is written to disk or to the database, and
 * the keys must never be committed. Changing them later stops every
 * existing subscription.
 */
final class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'pwa:vapid-keys';

    protected $description = 'Print a new VAPID key pair for web push (to set in the server environment).';

    public function handle(): int
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($options);
        // PHP on Windows ships its OpenSSL configuration next to php.exe and does not find it by itself.
        $bundled = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if ($key === false && is_file($bundled)) {
            $key = openssl_pkey_new([...$options, 'config' => $bundled]);
        }
        while (openssl_error_string() !== false) {
            // drop errors of the first attempt
        }
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if ($details === false || ! isset($details['ec']['d'], $details['ec']['x'], $details['ec']['y'])) {
            $this->error('This PHP cannot make P-256 keys (OpenSSL with EC support is needed).');

            return self::FAILURE;
        }
        $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $pad = fn (string $bytes): string => str_pad($bytes, 32, "\0", STR_PAD_LEFT);

        $this->line('Put these in the server environment (never in Git):');
        $this->line('PWA_VAPID_PUBLIC_KEY='.$encode("\x04".$pad($details['ec']['x']).$pad($details['ec']['y'])));
        $this->line('PWA_VAPID_PRIVATE_KEY='.$encode($pad($details['ec']['d'])));
        $this->line('PWA_VAPID_SUBJECT=mailto:<the platform support address>');

        return self::SUCCESS;
    }
}
