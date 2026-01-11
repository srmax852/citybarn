<?php

namespace App\Http\Controllers;

use App\Services\MegaMenuService;
use App\Models\SyncLog;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class MegaMenuController extends Controller
{
    /**
     * Generate and download the Qikify mega menu JSON file
     */
    public function download()
    {
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        try {
            $service = new MegaMenuService();
            $result = $service->generate();

            $filename = 'qikify_mega_menu_' . date('Y-m-d_H-i-s') . '.json';
            $content = json_encode($result['menu'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

            // Record sync time
            SyncLog::recordSync('mega_menu');

            return response($content, 200, [
                'Content-Type' => 'application/json',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Content-Length' => strlen($content),
            ]);

        } catch (\Exception $e) {
            Log::error('Mega Menu Download Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to generate mega menu',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
