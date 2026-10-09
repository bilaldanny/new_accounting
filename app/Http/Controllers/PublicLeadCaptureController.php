<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public, unauthenticated Lead Capture Form — the one piece of "Leads, Lead Sources & Capture
 * Forms" that Steps 1-2 deliberately left out of scope. Reachable with no login, embeddable in an
 * iframe on an external marketing site. A company is identified by its existing `companies.code` (an
 * already-non-secret identifier shown throughout the authenticated app) rather than a new token
 * system — the worst case of a guessed code is a spam Lead landing in that company's CRM, the same
 * risk profile as any public contact form, which matches the "basic honeypot + rate limit, no heavy
 * auth" spam-protection bar this feature was scoped to.
 */
class PublicLeadCaptureController extends Controller
{
    /**
     * The embeddable form page itself.
     */
    public function show(string $code): Response
    {
        $company = Company::query()->where('code', $code)->where('is_active', true)->first();

        abort_if($company === null, 404);

        return Inertia::render('public/leadcapture', [
            'companyCode' => $code,
            'companyName' => $company->name,
        ]);
    }

    /**
     * The form's submit target. A filled honeypot field looks like success to whatever submitted it
     * (no Lead is created, no error surfaced) — the standard way to not tip off a bot that it was
     * caught, rather than returning a validation error it could learn from.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'company_code' => 'required|string|max:50',
            'name' => 'bail|required|min:2|max:200',
            'company_name' => 'nullable|string|max:200',
            'email' => 'nullable|email|max:200',
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+\-\s()]+$/'],
            'notes' => 'nullable|string|max:2000',
            // Honeypot: a real visitor never sees or fills this field (hidden via CSS on the form). Not
            // constrained tighter than this — a validation error here would itself tip off a bot that
            // the field is being checked; `store()` below silently no-ops instead.
            'website_url' => 'nullable|string|max:500',
        ]);

        $company = Company::query()->where('code', $request->string('company_code'))->where('is_active', true)->first();

        abort_if($company === null, 404);

        if ($request->filled('website_url')) {
            return response()->json(['message' => 'Thank you.']);
        }

        Lead::createFromCapture($company->id, $request->only(['name', 'company_name', 'email', 'phone', 'notes']));

        return response()->json(['message' => 'Thank you. We will be in touch shortly.']);
    }
}
