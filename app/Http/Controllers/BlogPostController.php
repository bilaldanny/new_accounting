<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesIndexAndBulkDelete;
use App\Models\BlogPost;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Admin side of the public blog. Platform content: only the superadmin may use it (the menu is not granted to anyone else, and
 * the role is checked here too, so a menu ticked by mistake does not open it to a company role).
 */
class BlogPostController extends Controller
{
    use HandlesIndexAndBulkDelete;

    /**
     * @return array<string, mixed>
     */
    protected function postFormRules(?int $postId = null): array
    {
        $slugRule = Rule::unique('blog_posts', 'slug');

        if ($postId !== null) {
            $slugRule = $slugRule->ignore($postId);
        }

        return [
            'title' => 'bail|required|string|min:3|max:200',
            'slug' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slugRule],
            'excerpt' => 'nullable|string|max:500',
            'body' => 'bail|required|string|max:200000',
            'status' => ['bail', 'required', Rule::in(BlogPost::STATUSES)],
            'published_at' => 'nullable|date',
            'meta_title' => 'nullable|string|max:200',
            'meta_description' => 'nullable|string|max:500',
            'cover_image' => 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:2048',
            'remove_cover' => 'nullable|boolean',
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorizeBlogAdmin('/blogposts');

        $query = BlogPost::query()
            ->when($request->filled('status') && $request->status !== 'all', fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'));

        $posts = $this->paginateSorted($query, $this->withSafeSort($request));
        $posts->getCollection()->transform(fn (BlogPost $post) => $this->present($post));

        return response()->json(['data' => $posts, 'trash_count' => BlogPost::onlyTrashed()->count()]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeBlogAdmin('/blogposts/add');
        $request->validate($this->postFormRules());

        try {
            BlogPost::createPost($request, $request->user()?->id);
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function show($id): JsonResponse
    {
        $this->authorizeBlogAdmin('/blogposts');
        $post = BlogPost::query()->find($id);

        if ($post === null) {
            abort(404);
        }

        return response()->json($this->present($post));
    }

    public function update(Request $request, $id): JsonResponse
    {
        $this->authorizeBlogAdmin('/blogposts/:id/edit');
        $request->validate($this->postFormRules((int) $id));

        try {
            BlogPost::updatePost($request, $id);
        } catch (ValidationException|ModelNotFoundException|HttpExceptionInterface $e) {
            throw $e;
        } catch (Throwable $e) {
            return response()->json(['errormessage' => $e->getMessage()], 500);
        }

        return response()->json(['message' => 'Successfully Saved']);
    }

    public function destroy($id): JsonResponse
    {
        $this->authorizeSuperadmin();

        if (deletepermission('/blogposts/delete')) {
            BlogPost::deletePost($id);

            return response()->json(['message' => 'Successfully Deleted']);
        }

        return response()->json('406');
    }

    public function bulk_delete(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin();

        return $this->guardedBulkAction('/blogposts/delete', 'Successfully Deleted', function () use ($request) {
            BlogPost::query()->whereIn('id', (array) $request->all())->delete();
        });
    }

    public function bulk_delete_per(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin();

        return $this->guardedBulkAction('/blogposts/delete', 'Successfully Deleted', function () use ($request) {
            $posts = BlogPost::onlyTrashed()->whereIn('id', (array) $request->all())->get();

            foreach ($posts as $post) {
                if ($post->cover_image) {
                    Storage::disk('public')->delete($post->cover_image);
                }

                $post->forceDelete();
            }
        });
    }

    public function restore_records(Request $request): JsonResponse
    {
        $this->authorizeSuperadmin();

        return $this->guardedBulkAction('/blogposts/restore', 'Successfully Restored', function () use ($request) {
            BlogPost::onlyTrashed()->whereIn('id', (array) $request->all())->restore();
        });
    }

    public function trash(Request $request): JsonResponse
    {
        $this->authorizeBlogAdmin('/blogposts');

        $query = BlogPost::onlyTrashed()
            ->when($request->filled('search'), fn ($q) => $q->where('title', 'like', '%'.$request->search.'%'));

        $posts = $this->paginateSorted($query, $this->withSafeSort($request));
        $posts->getCollection()->transform(fn (BlogPost $post) => $this->present($post));

        return response()->json(['data' => $posts]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(BlogPost $post): array
    {
        return $post->toArray() + ['cover_url' => $post->coverUrl()];
    }

    private function authorizeBlogAdmin(string $menuPath): void
    {
        $this->authorizeSuperadmin();
        $this->authorizeMenuPermission($menuPath);
    }

    private function authorizeSuperadmin(): void
    {
        $roleName = strtolower(str_replace(' ', '', (string) (request()->user()?->rolename ?? '')));

        abort_unless($roleName === 'superadmin', 403);
    }

    private function withSafeSort(Request $request): Request
    {
        if (! in_array($request->input('sort_by'), BlogPost::SORTABLE, true)) {
            $request->merge(['sort_by' => 'created_at']);
        }

        if (! in_array($request->input('sort_type'), ['asc', 'desc'], true)) {
            $request->merge(['sort_type' => 'desc']);
        }

        return $request;
    }
}
