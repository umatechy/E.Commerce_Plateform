<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Http\Controllers;

use App\Domain\Catalog\Exceptions\CatalogImportException;
use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Services\ProductExport;
use App\Domain\Catalog\Services\ProductImport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase B40 — Module 06 §48–51: product import (preview, then confirm) and
 * export as CSV. Reading the catalog needs products.view; each imported row
 * is checked against products.create / products.update by ProductImport.
 */
final class ProductTransferController
{
    public function export(Request $request, ProductExport $export): StreamedResponse
    {
        Gate::forUser($request->user())->authorize('viewAny', Product::class);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(ProductExport::statuses())],
        ]);

        return $export->stream($filters, $request->user());
    }

    public function preview(Request $request, ProductImport $import): JsonResponse
    {
        $this->authorizeImport($request);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.ProductImport::MAX_KILOBYTES]]);

        try {
            return response()->json(['data' => $import->preview($request->file('file'), $request->user())]);
        } catch (CatalogImportException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    public function confirm(Request $request, string $import, ProductImport $imports): JsonResponse
    {
        $this->authorizeImport($request);

        try {
            return response()->json(['data' => $imports->confirm($import, $request->user())]);
        } catch (CatalogImportException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }
    }

    /** Importing creates or changes products: one of the two permissions is needed to start. */
    private function authorizeImport(Request $request): void
    {
        Gate::forUser($request->user())->authorize('import', Product::class);
    }
}
