<?php declare(strict_types=1);

namespace App\PageBuilder;

use App\Database\QueryBuilder;

/**
 * Dynamic data providers for elements that need database content at render time.
 *
 * Most elements are purely template-based (slot data = user input).
 * Dynamic elements enrich the slot data with live database content
 * before the Mustache template is rendered.
 */
class DynamicElements
{
    /** Registry: element slug => provider method name. */
    private static array $providers = [
        'recent-posts' => 'enrichRecentPosts',
    ];

    /**
     * Check if an element slug has a dynamic data provider.
     */
    public static function isDynamic(string $slug): bool
    {
        return isset(self::$providers[$slug]);
    }

    /**
     * Enrich slot data with dynamic content for a given element slug.
     * Returns the original slot data if no provider exists.
     */
    public static function enrich(string $slug, array $slotData): array
    {
        if (!isset(self::$providers[$slug])) {
            return $slotData;
        }

        $method = self::$providers[$slug];
        return self::$method($slotData);
    }

    /**
     * Provider: recent-posts
     * Fetches published posts and injects them as a 'posts' array.
     */
    private static function enrichRecentPosts(array $slotData): array
    {
        $count = (int) ($slotData['count'] ?? 6);
        if (!in_array($count, [3, 6, 9, 12], true)) {
            $count = 6;
        }

        $now = gmdate('Y-m-d H:i:s');

        $posts = QueryBuilder::query('content')
            ->select('content.*', 'users.username as author_name')
            ->leftJoin('users', 'users.id', '=', 'content.author_id')
            ->where('content.type', 'post')
            ->where('content.status', 'published')
            ->whereRaw(
                '(content.published_at IS NULL OR content.published_at <= :now)',
                [':now' => $now]
            )
            ->orderBy('content.published_at', 'DESC')
            ->limit($count)
            ->get();

        $formattedPosts = [];
        foreach ($posts as $post) {
            $publishedAt = $post['published_at'] ?? $post['created_at'] ?? '';
            $formattedDate = $publishedAt ? date('M j, Y', strtotime($publishedAt)) : '';

            $excerpt = $post['excerpt'] ?? '';
            if ($excerpt === '') {
                $excerpt = mb_substr(strip_tags($post['body'] ?? ''), 0, 160, 'UTF-8');
            }

            $formattedPosts[] = [
                'title'          => $post['title'] ?? '',
                'slug'           => $post['slug'] ?? '',
                'featured_image' => $post['featured_image'] ?? '',
                'excerpt'        => $excerpt,
                'formatted_date' => $formattedDate,
                'author_name'    => $post['author_name'] ?? 'Unknown',
            ];
        }

        $slotData['posts'] = $formattedPosts;

        return $slotData;
    }
}
