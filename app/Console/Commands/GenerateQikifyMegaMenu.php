<?php

namespace App\Console\Commands;

use App\Services\MegaMenuService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class GenerateQikifyMegaMenu extends Command
{
    protected $signature = 'shopify:generate-mega-menu';
    protected $description = 'Generate Qikify mega menu from Shopify collections and department APIs';

    public function handle()
    {
        $this->info('Starting Qikify Mega Menu generation...');

        try {
            $service = new MegaMenuService();

            $this->info('Generating mega menu (this may take a while)...');
            $result = $service->generate();

            // Display stats
            $stats = $result['stats'];
            $this->info('');
            $this->info('Statistics:');
            $this->line("  - Collections found: {$stats['collections']}");
            $this->line("  - Departments found: {$stats['departments']}");
            $this->line("  - Sub-departments found: {$stats['sub_departments']}");
            $this->line("  - Categories added: {$stats['categories']}");

            // Display logs
            if (!empty($stats['logs'])) {
                $this->info('');
                $this->info('Processing logs:');
                foreach ($stats['logs'] as $log) {
                    $this->line("  {$log}");
                }
            }

            // Save to file
            $filename = 'qikify_mega_menu_' . date('Y-m-d_H-i-s') . '.json';
            $filepath = storage_path('app/mega_menu/' . $filename);

            if (!file_exists(dirname($filepath))) {
                mkdir(dirname($filepath), 0755, true);
            }

            file_put_contents($filepath, json_encode($result['menu'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->info('');
            $this->info('Mega menu generated successfully!');
            $this->info('File saved to: ' . $filepath);
            $this->info('Total menu items: ' . count($result['menu']['megamenu']));

        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            Log::error('Mega Menu Generation Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return 1;
        }

        return 0;
    }
}
