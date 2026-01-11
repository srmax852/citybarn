<?php

namespace App\Exports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductsExport implements FromCollection, WithHeadings, WithMapping
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return Product::with(['department', 'subDepartment'])->get();
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        return [
            'ID',
            'Title',
            'Price',
            'Barcode',
            'Department',
            'Sub-Department',
            'Shopify Product ID',
            'Created At',
            'Updated At'
        ];
    }

    /**
     * @param Product $product
     * @return array
     */
    public function map($product): array
    {
        return [
            $product->id,
            $product->title,
            $product->price,
            $product->barcode ?? 'N/A',
            $product->department->name ?? 'N/A',
            $product->subDepartment->name ?? 'N/A',
            $product->shopify_product_id ?? 'N/A',
            $product->created_at->format('Y-m-d H:i:s'),
            $product->updated_at->format('Y-m-d H:i:s')
        ];
    }
}
