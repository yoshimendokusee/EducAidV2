<?php

namespace App\Http\Controllers;

use App\Services\FileCompressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FileCompressionController extends Controller
{
    private FileCompressionService $compressionService;

    public function __construct(FileCompressionService $compressionService)
    {
        $this->compressionService = $compressionService;
    }

    /**
     * Compress distribution files
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function compressDistribution(Request $request): JsonResponse
    {
        $request->validate([
            'distribution_id' => 'required|integer'
        ]);

        $adminId = auth('admin')->user()?->id;

        $result = $this->compressionService->compressDistribution(
            $request->input('distribution_id'),
            $adminId
        );

        return response()->json($result);
    }

    /**
     * Decompress distribution archive
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function decompressDistribution(Request $request): JsonResponse
    {
        $request->validate([
            'distribution_id' => 'required|integer',
            'extract_path' => 'required|string'
        ]);

        $result = $this->compressionService->decompressDistribution(
            $request->input('distribution_id'),
            $request->input('extract_path')
        );

        return response()->json($result);
    }

    /**
     * Cleanup old archives
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function cleanupOldArchives(Request $request): JsonResponse
    {
        $daysOld = $request->input('days_old', 30);

        $result = $this->compressionService->cleanupOldArchives($daysOld);

        return response()->json($result);
    }
}
