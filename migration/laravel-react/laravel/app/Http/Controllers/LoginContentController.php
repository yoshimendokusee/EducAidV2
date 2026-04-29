<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoginContentController extends Controller
{
    private function isSuperAdmin(): bool
    {
        return isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin';
    }

    private function validateCsrfToken(string $formName, string $token): bool
    {
        $tokens = $_SESSION['csrf_tokens'][$formName] ?? [];
        if (!is_array($tokens) || $token === '') {
            return false;
        }

        foreach ($tokens as $index => $storedToken) {
            if (hash_equals((string) $storedToken, $token)) {
                unset($tokens[$index]);
                $_SESSION['csrf_tokens'][$formName] = array_values($tokens);
                return true;
            }
        }

        return false;
    }

    private function sanitizeHtml(string $html): string
    {
        $allowedTags = '<p><br><b><strong><i><em><u><a><span><div><h1><h2><h3><h4><h5><h6><ul><ol><li>';
        $cleanHtml = strip_tags($html, $allowedTags);

        return preg_replace('/<([a-z][a-z0-9]*)[^>]*?(on\w+|style)\s*=\s*[\'\"]?[^\'\"]*[\'\"]?[^>]*?>/i', '<$1>', $cleanHtml) ?? $cleanHtml;
    }

    private function logAdminActivity(int $adminId, string $action, array $details): void
    {
        try {
            DB::table('admin_activity_log')->insert([
                'admin_id' => $adminId,
                'action' => $action,
                'details' => json_encode($details),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            // Logging should never block content updates.
        }
    }

    public function save(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 403);
        }

        if (!$this->validateCsrfToken('edit_login_content', (string) $request->input('csrf_token', ''))) {
            return response()->json(['success' => false, 'message' => 'Invalid security token. Please refresh the page.'], 403);
        }

        $municipalityId = (int) $request->input('municipality_id', 1);
        $savedCount = 0;
        $errors = [];

        foreach ($request->all() as $key => $value) {
            if ($key === 'municipality_id' || $key === 'csrf_token' || !str_starts_with((string) $key, 'login_')) {
                continue;
            }

            $cleanHtml = $this->sanitizeHtml((string) $value);

            try {
                DB::table('login_content_blocks')->updateOrInsert(
                    ['municipality_id' => $municipalityId, 'block_key' => $key],
                    ['html' => $cleanHtml, 'updated_at' => now(), 'created_at' => now()]
                );
                $savedCount++;
            } catch (\Throwable) {
                $errors[] = "Failed to save block: $key";
            }
        }

        if (isset($_SESSION['admin_id'])) {
            $this->logAdminActivity((int) $_SESSION['admin_id'], 'edit_login_page_content', [
                'page' => 'unified_login',
                'blocks_saved' => $savedCount,
                'municipality_id' => $municipalityId,
            ]);
        }

        if ($errors) {
            return response()->json([
                'success' => false,
                'message' => 'Some blocks failed to save',
                'saved' => $savedCount,
                'errors' => $errors,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Content saved successfully',
            'saved' => $savedCount,
        ]);
    }

    public function toggleSection(Request $request): JsonResponse
    {
        if (!$this->isSuperAdmin()) {
            return response()->json(['success' => false, 'error' => 'Unauthorized'], 403);
        }

        if (!$this->validateCsrfToken('toggle_section', (string) $request->input('csrf_token', ''))) {
            return response()->json(['success' => false, 'error' => 'Invalid security token. Please refresh the page.'], 403);
        }

        $sectionKey = (string) $request->input('section_key', '');
        $isVisible = filter_var($request->input('is_visible', true), FILTER_VALIDATE_BOOLEAN);
        $municipalityId = 1;

        if ($sectionKey === '') {
            return response()->json(['success' => false, 'error' => 'Section key is required'], 422);
        }

        try {
            DB::table('login_content_blocks')->updateOrInsert(
                ['municipality_id' => $municipalityId, 'block_key' => $sectionKey],
                ['html' => '', 'is_visible' => $isVisible, 'updated_at' => now(), 'created_at' => now()]
            );
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'error' => 'Failed to update visibility: ' . $e->getMessage()], 500);
        }

        if (isset($_SESSION['admin_id'])) {
            $this->logAdminActivity((int) $_SESSION['admin_id'], $isVisible ? 'show_section' : 'hide_section', [
                'table' => 'login_content_blocks',
                'section_key' => $sectionKey,
                'visible' => $isVisible,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => $isVisible ? 'Section is now visible' : 'Section is now hidden (archived)',
            'is_visible' => $isVisible,
        ]);
    }
}
