<?php

namespace App\Http\Controllers;

use App\Services\System\FranchiseGroups;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The Help Center (published articles of the platform, readable by every signed-in user; questions go to the support tickets of the
 * billing wave) and the read-only franchise overview of the groups a user owns.
 */
class HelpCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorizeMenuPermission('/help');
        $data = $request->validate(['q' => 'nullable|string|max:100', 'category' => 'nullable|string|max:60']);

        $articles = DB::table('help_articles')->where('is_published', true)
            ->when(! empty($data['category']), fn ($q) => $q->where('category', $data['category']))
            ->when(! empty($data['q']), fn ($q) => $q->where(fn ($w) => $w->where('title', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['q']).'%')->orWhere('body', 'like', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $data['q']).'%')))
            ->orderBy('category')->orderBy('sort_order')->orderBy('title')->get(['id', 'title', 'slug', 'category', 'sort_order']);

        return response()->json(['data' => $articles, 'categories' => DB::table('help_articles')->where('is_published', true)->distinct()->orderBy('category')->pluck('category')]);
    }

    public function show(string $slug): JsonResponse
    {
        $this->authorizeMenuPermission('/help');
        $article = DB::table('help_articles')->where('is_published', true)->where('slug', $slug)->first(['id', 'title', 'slug', 'category', 'body', 'updated_at']);
        abort_if($article === null, 404);

        return response()->json(['data' => $article]);
    }

    public function franchise(FranchiseGroups $groups): JsonResponse
    {
        $this->authorizeMenuPermission('/franchise');

        return response()->json(['data' => $groups->overviewFor((int) Auth::id())]);
    }
}
