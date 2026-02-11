<?php declare(strict_types=1);

namespace App\PageBuilder;

use App\Database\QueryBuilder;

/**
 * Seeds the element catalogue with common starter elements.
 */
class SeedElements
{
    public static function seed(): int
    {
        $count = 0;
        foreach (self::definitions() as $def) {
            $existing = QueryBuilder::query('elements')
                ->select('id')
                ->where('slug', $def['slug'])
                ->first();

            if ($existing !== null) {
                continue;
            }

            QueryBuilder::query('elements')->insert($def);
            $count++;
        }
        return $count;
    }

    public static function definitions(): array
    {
        return [
            self::heroSection(),
            self::textSection(),
            self::featureGrid(),
            self::ctaBanner(),
            self::imageText(),
            self::testimonialSection(),
            self::faqSection(),
            self::recentPosts(),
        ];
    }

    private static function heroSection(): array
    {
        return [
            'slug'        => 'hero-section',
            'name'        => 'Hero Section',
            'description' => 'Full-width hero banner with headline, description, and call-to-action button.',
            'category'    => 'hero',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'title', 'label' => 'Headline', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Description', 'type' => 'richtext', 'required' => false],
                ['key' => 'background_image', 'label' => 'Background Image', 'type' => 'image', 'required' => false],
                ['key' => 'cta', 'label' => 'CTA Button', 'type' => 'link', 'required' => false, 'default' => ['url' => '', 'text' => 'Learn More', 'target' => '_self']],
                ['key' => 'alignment', 'label' => 'Text Alignment', 'type' => 'select', 'options' => ['left', 'center', 'right'], 'default' => 'center'],
            ]),
            'html_template' => <<<'HTML'
<section class="hero-inner" {{#background_image}}style="background-image:url('{{background_image}}')"{{/background_image}}>
    <div class="hero-content hero-align-{{alignment}}">
        <h2>{{title}}</h2>
        {{{description}}}
        {{#cta.url}}
        <a href="{{cta.url}}" target="{{cta.target}}" class="hero-cta">{{cta.text}}</a>
        {{/cta.url}}
    </div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-hero-section {
    margin: 0 calc(-50vw + 50%);
    width: 100vw;
}
.lcms-el-hero-section .hero-inner {
    padding: 4rem 2rem;
    background-size: cover;
    background-position: center;
    background-color: var(--color-bg-alt, #f8fafc);
}
.lcms-el-hero-section .hero-content {
    max-width: var(--container-width, 1100px);
    margin: 0 auto;
}
.lcms-el-hero-section .hero-align-center { text-align: center; }
.lcms-el-hero-section .hero-align-left { text-align: left; }
.lcms-el-hero-section .hero-align-right { text-align: right; }
.lcms-el-hero-section h2 {
    font-size: 2.5rem;
    margin-bottom: 1rem;
    color: var(--color-text, #1e293b);
}
.lcms-el-hero-section .hero-cta {
    display: inline-block;
    padding: 0.75rem 2rem;
    background: var(--color-primary, #2563eb);
    color: #fff;
    text-decoration: none;
    border-radius: 6px;
    margin-top: 1rem;
    font-weight: 600;
}
.lcms-el-hero-section .hero-cta:hover {
    background: var(--color-primary-hover, #1d4ed8);
}
CSS,
        ];
    }

    private static function textSection(): array
    {
        return [
            'slug'        => 'text-section',
            'name'        => 'Text Section',
            'description' => 'Simple section with an optional heading and rich text body.',
            'category'    => 'content',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'required' => false],
                ['key' => 'body', 'label' => 'Body', 'type' => 'richtext', 'required' => true],
            ]),
            'html_template' => <<<'HTML'
<section class="text-inner">
    {{#heading}}<h2>{{heading}}</h2>{{/heading}}
    <div class="text-body">{{{body}}}</div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-text-section .text-inner {
    padding: 2rem 0;
}
.lcms-el-text-section h2 {
    margin-bottom: 1rem;
    font-size: 1.75rem;
    color: var(--color-text, #1e293b);
}
.lcms-el-text-section .text-body {
    line-height: 1.8;
    color: var(--color-text, #1e293b);
}
CSS,
        ];
    }

    private static function featureGrid(): array
    {
        return [
            'slug'        => 'feature-grid',
            'name'        => 'Feature Grid',
            'description' => 'Grid of feature items with icon, title, and description.',
            'category'    => 'features',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'heading', 'label' => 'Section Heading', 'type' => 'text', 'required' => false],
                ['key' => 'columns', 'label' => 'Columns', 'type' => 'select', 'options' => ['2', '3', '4'], 'default' => '3'],
                ['key' => 'features', 'label' => 'Features', 'type' => 'list', 'min_items' => 1, 'max_items' => 12,
                 'sub_slots' => [
                    ['key' => 'icon', 'label' => 'Icon/Emoji', 'type' => 'text'],
                    ['key' => 'title', 'label' => 'Title', 'type' => 'text'],
                    ['key' => 'description', 'label' => 'Description', 'type' => 'richtext'],
                ]],
            ]),
            'html_template' => <<<'HTML'
<section class="features-inner">
    {{#heading}}<h2>{{heading}}</h2>{{/heading}}
    <div class="features-grid features-cols-{{columns}}">
        {{#features}}
        <div class="feature-item">
            {{#icon}}<div class="feature-icon">{{icon}}</div>{{/icon}}
            <h3>{{title}}</h3>
            {{{description}}}
        </div>
        {{/features}}
    </div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-feature-grid .features-inner {
    padding: 2rem 0;
}
.lcms-el-feature-grid h2 {
    text-align: center;
    margin-bottom: 2rem;
    font-size: 1.75rem;
}
.lcms-el-feature-grid .features-grid {
    display: grid;
    gap: 2rem;
}
.lcms-el-feature-grid .features-cols-2 { grid-template-columns: repeat(2, 1fr); }
.lcms-el-feature-grid .features-cols-3 { grid-template-columns: repeat(3, 1fr); }
.lcms-el-feature-grid .features-cols-4 { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 768px) {
    .lcms-el-feature-grid .features-grid { grid-template-columns: 1fr; }
}
.lcms-el-feature-grid .feature-item {
    padding: 1.5rem;
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: 8px;
    text-align: center;
}
.lcms-el-feature-grid .feature-icon {
    font-size: 2rem;
    margin-bottom: 0.75rem;
}
.lcms-el-feature-grid .feature-item h3 {
    margin-bottom: 0.5rem;
    font-size: 1.1rem;
}
CSS,
        ];
    }

    private static function ctaBanner(): array
    {
        return [
            'slug'        => 'cta-banner',
            'name'        => 'CTA Banner',
            'description' => 'Call-to-action banner with title, description, and button.',
            'category'    => 'cta',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'title', 'label' => 'Title', 'type' => 'text', 'required' => true],
                ['key' => 'description', 'label' => 'Description', 'type' => 'richtext', 'required' => false],
                ['key' => 'button', 'label' => 'Button', 'type' => 'link', 'required' => true, 'default' => ['url' => '', 'text' => 'Get Started', 'target' => '_self']],
            ]),
            'html_template' => <<<'HTML'
<section class="cta-inner">
    <h2>{{title}}</h2>
    {{{description}}}
    {{#button.url}}
    <a href="{{button.url}}" target="{{button.target}}" class="cta-button">{{button.text}}</a>
    {{/button.url}}
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-cta-banner .cta-inner {
    padding: 3rem 2rem;
    text-align: center;
    background: var(--color-primary, #2563eb);
    color: #fff;
    border-radius: 8px;
    margin: 2rem 0;
}
.lcms-el-cta-banner h2 {
    color: #fff;
    margin-bottom: 0.75rem;
    font-size: 1.75rem;
}
.lcms-el-cta-banner .cta-inner p { color: rgba(255,255,255,0.9); }
.lcms-el-cta-banner .cta-button {
    display: inline-block;
    padding: 0.75rem 2rem;
    background: #fff;
    color: var(--color-primary, #2563eb);
    text-decoration: none;
    border-radius: 6px;
    font-weight: 600;
    margin-top: 1rem;
}
CSS,
        ];
    }

    private static function imageText(): array
    {
        return [
            'slug'        => 'image-text',
            'name'        => 'Image + Text',
            'description' => 'Side-by-side layout with image and text content.',
            'category'    => 'content',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'image', 'label' => 'Image', 'type' => 'image', 'required' => true],
                ['key' => 'image_alt', 'label' => 'Image Alt Text', 'type' => 'text', 'required' => false],
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'required' => false],
                ['key' => 'body', 'label' => 'Body', 'type' => 'richtext', 'required' => true],
                ['key' => 'image_position', 'label' => 'Image Position', 'type' => 'select', 'options' => ['left', 'right'], 'default' => 'left'],
            ]),
            'html_template' => <<<'HTML'
<section class="imgtext-inner imgtext-{{image_position}}">
    <div class="imgtext-image">
        <img src="{{image}}" alt="{{image_alt}}">
    </div>
    <div class="imgtext-content">
        {{#heading}}<h2>{{heading}}</h2>{{/heading}}
        {{{body}}}
    </div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-image-text .imgtext-inner {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    align-items: center;
    padding: 2rem 0;
}
.lcms-el-image-text .imgtext-right .imgtext-image { order: 2; }
.lcms-el-image-text .imgtext-right .imgtext-content { order: 1; }
.lcms-el-image-text img {
    width: 100%;
    border-radius: 8px;
    object-fit: cover;
}
.lcms-el-image-text h2 { margin-bottom: 1rem; }
@media (max-width: 768px) {
    .lcms-el-image-text .imgtext-inner {
        grid-template-columns: 1fr;
    }
    .lcms-el-image-text .imgtext-right .imgtext-image,
    .lcms-el-image-text .imgtext-right .imgtext-content { order: unset; }
}
CSS,
        ];
    }

    private static function testimonialSection(): array
    {
        return [
            'slug'        => 'testimonial-section',
            'name'        => 'Testimonials',
            'description' => 'Section with customer testimonial quotes.',
            'category'    => 'testimonials',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'required' => false],
                ['key' => 'testimonials', 'label' => 'Testimonials', 'type' => 'list', 'min_items' => 1, 'max_items' => 10,
                 'sub_slots' => [
                    ['key' => 'quote', 'label' => 'Quote', 'type' => 'richtext'],
                    ['key' => 'author', 'label' => 'Author Name', 'type' => 'text'],
                    ['key' => 'role', 'label' => 'Author Role', 'type' => 'text'],
                ]],
            ]),
            'html_template' => <<<'HTML'
<section class="testimonials-inner">
    {{#heading}}<h2>{{heading}}</h2>{{/heading}}
    <div class="testimonials-grid">
        {{#testimonials}}
        <blockquote class="testimonial-item">
            <div class="testimonial-quote">{{{quote}}}</div>
            <footer>
                <strong>{{author}}</strong>
                {{#role}}<span class="testimonial-role">{{role}}</span>{{/role}}
            </footer>
        </blockquote>
        {{/testimonials}}
    </div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-testimonial-section .testimonials-inner {
    padding: 2rem 0;
}
.lcms-el-testimonial-section h2 {
    text-align: center;
    margin-bottom: 2rem;
}
.lcms-el-testimonial-section .testimonials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 1.5rem;
}
.lcms-el-testimonial-section .testimonial-item {
    padding: 1.5rem;
    border-left: 4px solid var(--color-primary, #2563eb);
    background: var(--color-bg-alt, #f8fafc);
    border-radius: 0 8px 8px 0;
    margin: 0;
}
.lcms-el-testimonial-section .testimonial-quote {
    font-style: italic;
    margin-bottom: 1rem;
    line-height: 1.6;
}
.lcms-el-testimonial-section .testimonial-role {
    display: block;
    color: var(--color-text-muted, #64748b);
    font-size: 0.9rem;
}
CSS,
        ];
    }

    private static function faqSection(): array
    {
        return [
            'slug'        => 'faq-section',
            'name'        => 'FAQ Section',
            'description' => 'Frequently asked questions with expandable answers.',
            'category'    => 'content',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'heading', 'label' => 'Heading', 'type' => 'text', 'required' => false],
                ['key' => 'items', 'label' => 'FAQ Items', 'type' => 'list', 'min_items' => 1, 'max_items' => 20,
                 'sub_slots' => [
                    ['key' => 'question', 'label' => 'Question', 'type' => 'text'],
                    ['key' => 'answer', 'label' => 'Answer', 'type' => 'richtext'],
                ]],
            ]),
            'html_template' => <<<'HTML'
<section class="faq-inner">
    {{#heading}}<h2>{{heading}}</h2>{{/heading}}
    <div class="faq-list">
        {{#items}}
        <details class="faq-item">
            <summary>{{question}}</summary>
            <div class="faq-answer">{{{answer}}}</div>
        </details>
        {{/items}}
    </div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-faq-section .faq-inner {
    padding: 2rem 0;
    max-width: 800px;
    margin: 0 auto;
}
.lcms-el-faq-section h2 {
    text-align: center;
    margin-bottom: 2rem;
}
.lcms-el-faq-section .faq-item {
    border: 1px solid var(--color-border, #e2e8f0);
    border-radius: 6px;
    margin-bottom: 0.75rem;
    overflow: hidden;
}
.lcms-el-faq-section summary {
    padding: 1rem 1.25rem;
    cursor: pointer;
    font-weight: 600;
    background: var(--color-bg-alt, #f8fafc);
    list-style: none;
}
.lcms-el-faq-section summary::-webkit-details-marker { display: none; }
.lcms-el-faq-section summary::before {
    content: '+';
    margin-right: 0.75rem;
    font-weight: bold;
}
.lcms-el-faq-section details[open] summary::before { content: '\2212'; }
.lcms-el-faq-section .faq-answer {
    padding: 1rem 1.25rem;
    line-height: 1.7;
}
CSS,
        ];
    }

    private static function recentPosts(): array
    {
        return [
            'slug'        => 'recent-posts',
            'name'        => 'Recent Posts',
            'description' => 'Dynamic grid of recent blog posts. Configurable count, columns, and display options.',
            'category'    => 'content',
            'status'      => 'active',
            'slots_json'  => json_encode([
                ['key' => 'heading', 'label' => 'Section Heading', 'type' => 'text', 'required' => false, 'default' => 'Recent Posts'],
                ['key' => 'count', 'label' => 'Number of Posts', 'type' => 'select', 'options' => ['3', '6', '9', '12'], 'default' => '6'],
                ['key' => 'columns', 'label' => 'Grid Columns', 'type' => 'select', 'options' => ['1', '2', '3', '4'], 'default' => '3'],
                ['key' => 'show_image', 'label' => 'Show Featured Image', 'type' => 'boolean', 'default' => true],
                ['key' => 'show_excerpt', 'label' => 'Show Excerpt', 'type' => 'boolean', 'default' => true],
                ['key' => 'show_date', 'label' => 'Show Date', 'type' => 'boolean', 'default' => true],
                ['key' => 'show_author', 'label' => 'Show Author', 'type' => 'boolean', 'default' => true],
            ]),
            'html_template' => <<<'HTML'
<section class="recent-posts-inner">
    {{#heading}}<h2>{{heading}}</h2>{{/heading}}
    {{^posts}}<p class="no-posts">No posts published yet.</p>{{/posts}}
    <div class="recent-posts-grid recent-posts-cols-{{columns}}">
        {{#posts}}
        <article class="recent-post-card">
            {{#show_image}}{{#featured_image}}
            <div class="recent-post-image">
                <a href="/blog/{{slug}}"><img src="{{featured_image}}" alt="{{title}}"></a>
            </div>
            {{/featured_image}}{{/show_image}}
            <div class="recent-post-body">
                <h3><a href="/blog/{{slug}}">{{title}}</a></h3>
                {{#show_date}}<time class="recent-post-date">{{formatted_date}}</time>{{/show_date}}
                {{#show_author}}<span class="recent-post-author">by {{author_name}}</span>{{/show_author}}
                {{#show_excerpt}}{{#excerpt}}<p class="recent-post-excerpt">{{excerpt}}</p>{{/excerpt}}{{/show_excerpt}}
            </div>
        </article>
        {{/posts}}
    </div>
</section>
HTML,
            'css' => <<<'CSS'
.lcms-el-recent-posts .recent-posts-inner { padding: 2rem 0; }
.lcms-el-recent-posts h2 { text-align: center; margin-bottom: 2rem; font-size: 1.75rem; }
.lcms-el-recent-posts .recent-posts-grid { display: grid; gap: 1.5rem; }
.lcms-el-recent-posts .recent-posts-cols-1 { grid-template-columns: 1fr; }
.lcms-el-recent-posts .recent-posts-cols-2 { grid-template-columns: repeat(2, 1fr); }
.lcms-el-recent-posts .recent-posts-cols-3 { grid-template-columns: repeat(3, 1fr); }
.lcms-el-recent-posts .recent-posts-cols-4 { grid-template-columns: repeat(4, 1fr); }
@media (max-width: 768px) { .lcms-el-recent-posts .recent-posts-grid { grid-template-columns: 1fr; } }
.lcms-el-recent-posts .recent-post-card { border: 1px solid var(--color-border, #e2e8f0); border-radius: 8px; overflow: hidden; }
.lcms-el-recent-posts .recent-post-image img { width: 100%; height: 200px; object-fit: cover; display: block; }
.lcms-el-recent-posts .recent-post-body { padding: 1rem 1.25rem; }
.lcms-el-recent-posts .recent-post-body h3 { margin-bottom: 0.5rem; font-size: 1.1rem; }
.lcms-el-recent-posts .recent-post-body h3 a { color: var(--color-text, #1e293b); text-decoration: none; }
.lcms-el-recent-posts .recent-post-body h3 a:hover { color: var(--color-primary, #2563eb); }
.lcms-el-recent-posts .recent-post-date,
.lcms-el-recent-posts .recent-post-author { font-size: 0.85rem; color: var(--color-text-muted, #64748b); }
.lcms-el-recent-posts .recent-post-author { margin-left: 0.5rem; }
.lcms-el-recent-posts .recent-post-excerpt { margin-top: 0.75rem; color: var(--color-text, #1e293b); line-height: 1.6; }
.lcms-el-recent-posts .no-posts { text-align: center; color: var(--color-text-muted, #64748b); }
CSS,
        ];
    }
}
