<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as BaseVerifier;

class VerifyCsrfToken extends BaseVerifier
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        '/provider/request/*',
        '/provider/profile/available',
        '/common/socket',
        '/stripe/account',
        '/rzp/success',
         'provider/rzp/success',
        '/rzp/flow',
        '/contact/us',
        '/account/kit'
    ];
}
