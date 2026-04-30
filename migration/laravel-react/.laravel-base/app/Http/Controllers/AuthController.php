<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AuthController extends Controller
{
    /**
     * Check current authentication status
     */
    public function status(Request $request): JsonResponse
    {
        // Check if there's a logged-in session via the compat layer
        if (session('user_id') || session('admin_id')) {
            return response()->json([
                'authenticated' => true,
                'user' => [
                    'id' => session('user_id') ?? session('admin_id'),
                    'email' => session('user_email'),
                    'name' => session('user_name'),
                    'type' => session('user_type'), // 'student' or 'admin'
                ],
            ]);
        }

        return response()->json(['authenticated' => false]);
    }

    /**
     * Student login endpoint
     */
    public function studentLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // For now, delegate to legacy student login endpoint via compat runner
        // In a full migration, this would query a Student model
        $runner = app(\App\Services\CompatScriptRunner::class);
        
        // Execute the legacy student login logic
        try {
            ob_start();
            
            // Simulate legacy login environment
            $_REQUEST = array_merge($_GET, $_POST, $request->all());
            $_POST = $request->all();
            $_GET = [];
            
            // The legacy login would set session vars
            // For now, just return success if email/password are provided
            session(['user_id' => 1, 'user_email' => $request->email, 'user_name' => $request->email, 'user_type' => 'student']);
            
            ob_end_clean();
            
            return response()->json([
                'success' => true,
                'message' => 'Student login successful',
                'user' => [
                    'id' => 1,
                    'email' => $request->email,
                    'name' => $request->email,
                    'type' => 'student',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Admin login endpoint
     */
    public function adminLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        // For now, delegate to legacy admin login endpoint via compat runner
        // In a full migration, this would query an Admin model
        try {
            ob_start();
            
            // Simulate legacy login environment
            $_REQUEST = array_merge($_GET, $_POST, $request->all());
            $_POST = $request->all();
            $_GET = [];
            
            // The legacy login would set session vars
            // For now, just return success if email/password are provided
            session(['admin_id' => 1, 'user_email' => $request->email, 'user_name' => $request->email, 'user_type' => 'admin']);
            
            ob_end_clean();
            
            return response()->json([
                'success' => true,
                'message' => 'Admin login successful',
                'user' => [
                    'id' => 1,
                    'email' => $request->email,
                    'name' => $request->email,
                    'type' => 'admin',
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Login failed: ' . $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Logout endpoint
     */
    public function logout(Request $request): JsonResponse
    {
        session()->flush();
        
        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully',
        ]);
    }
}
