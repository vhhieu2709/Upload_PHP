<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AuthCustomMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        if (!session('user_id')) {
            session()->put('url.intended', $request->fullUrl());
            return redirect()->route('login')
                ->with('error', 'Vui lòng đăng nhập để tiếp tục.');
        }

        if (!auth()->check()) {
            auth()->loginUsingId(session('user_id'));
        }

        return $next($request);
    }
}