<?php

namespace App\Http\Controllers\V1;

use App\Helpers\LocaleHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\PostStoreRequest;
use App\Http\Requests\V1\PostUpdateRequest;
use App\Http\Resources\V1\PostCollection;
use App\Http\Resources\V1\PostResource;
use App\Models\Post;
use App\Models\Tag;
use App\Rules\V1\ValidCategoryId;
use App\Rules\V1\ValidTagId;

use App\Services\MediumService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PostController extends Controller
{

    public function __construct()
    {
        $this->authorizeResource(Post::class, 'post');
    }

    public function destroy(Post $post)
    {
        DB::transaction(function () use ($post) {
            $post->tags()->detach();
            $post->delete();
        });

        return response()->noContent();
    }

    /**
     * @throws RequestException
     */
    public function update(PostUpdateRequest $request, Post $post, MediumService $mediumService)
    {
        $input = $request->safe()->collect()->filterBlankable(['post_content']);

        $toBeUpdatedData = $input->except(['tag_ids'])->toArray();

        $post->update($toBeUpdatedData);

        if (isset($input['tag_ids'])) {
            $post->tags()->sync($input['tag_ids']);
            Tag::whereIn('id', $input['tag_ids'])->touch('used_at');
        }

        if (app()->isProduction() && $input->has('should_publish_medium') && $input['should_publish_medium']) {
            $mediumService->postUnderPublication($input->toArray(), $post);
        }

        return response()->noContent();
    }

    public function show(Post $post)
    {
        $post->load(['tags', 'category']);

        return PostResource::make($post);
    }

    /**
     * @throws RequestException
     */
    public function store(PostStoreRequest $request, LocaleHelper $localeHelper, MediumService $mediumService)
    {
        $input = $request->safe()->collect();

        if ($input->has('locale')) {
            $input = $input->merge(['locale' => $localeHelper->normalizeLocale($input['locale'])]);
        }

        $toBeInsertedData = $input->filterBlankable(['post_content'], ['tag_ids'])->toArray();

        if (!Arr::has($toBeInsertedData, 'created_at')) {
            array_merge($toBeInsertedData, ['created_at' => now()]);
        }

        $post = DB::transaction(function () use ($toBeInsertedData, $input) {
            $post = Auth::user()->posts()->create($toBeInsertedData);
            if (isset($input['tag_ids'])) {
                $post->tags()->attach($input['tag_ids']);
                Tag::whereIn('id', $input['tag_ids'])->touch('used_at');
            }

            return $post;
        });

        if (app()->isProduction() && $input->has('should_publish_medium') && $input['should_publish_medium']) {
            $mediumService->postUnderPublication($input->toArray(), $post);
        }

        return PostResource::make($post);
    }

    public function index(Request $request, LocaleHelper $localeHelper)
    {
        $input = $request->validate(rules: [
            'tag_ids' => ['array', 'nullable'],
            'tag_ids.*' => ['required', new ValidTagId()],
            'category_id' => ['nullable', new ValidCategoryId()],
            'sort' => ['nullable', Rule::in(['created_at', 'updated_at'])],
            'search' => ['nullable', 'string'],
        ]);

        $input = collect($input)->filter();

        $posts = Post::query()->with(['tags', 'category'])
            ->when($input->has('tag_ids'), function (Builder $postBuilder) use ($input) {
                foreach ($input['tag_ids'] as $tagId) {
                    $postBuilder->whereHas('tags', fn(Builder $tagBuilder) => $tagBuilder->where('tags.id', $tagId));
                }
            })
            ->when($input->has('category_id') && $input['category_id'] != 1,
                function (Builder $postBuilder) use ($input) {
                    $postBuilder->whereRelation('category', 'id', $input['category_id']);
                })
            ->when($input->has('sort'), fn(Builder $postBuilder) => $postBuilder->orderByDesc($input['sort']),
                fn(Builder $postBuilder) => $postBuilder->orderByDesc('created_at')
            )
            ->when($input->has('search'),
                function (Builder $postBuilder) use ($input) {
                    $searchTerm = "%${input['search']}%";
                    $postBuilder->where(function (Builder $postBuilder) use ($searchTerm) {
                        $postBuilder->where('post_title', 'like', $searchTerm)
                            ->orWhere('post_content', 'like', $searchTerm);
                    });
//                    $postBuilder->whereFullText(['post_title', 'post_content'], $input['search']);
                })
            ->when(!Auth::check(), fn(Builder $postBuilder) => $postBuilder->where('is_public', true))
            ->whereLocale($localeHelper->normalizeLocale(App::currentLocale()))
            ->paginate(10)->withQueryString();

        return PostCollection::make($posts);
    }
}
