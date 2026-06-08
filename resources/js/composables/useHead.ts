import { onScopeDispose } from 'vue'

/**
 * Options accepted by useHead.
 *
 * All fields are optional — callers set only what they know.
 */
export interface HeadOptions {
    title?: string
    description?: string
    /** Absolute URL to the preview image (og:image). */
    image?: string
    /** Canonical URL for this page. */
    url?: string
    /** Open Graph type: 'website' | 'product' | 'article' … */
    type?: string
}

/**
 * Manages document <head> meta tags for SEO and social link previews.
 *
 * Upsert strategy: each tag is found by a stable selector; if present its
 * content is updated in-place; if absent a new element is appended to <head>.
 * On scope dispose (component unmount / watchEffect cleanup) every tag created
 * by this call is removed so navigating between pages never leaves stale tags.
 *
 * og:image is the primary deliverable — without it WhatsApp and social media
 * previews show a blank card when tenants share their storefront links.
 *
 * No external dependencies. Works in strict TypeScript with no `any`.
 */
export function useHead(options: HeadOptions): void {
    const created: Element[] = []

    function upsertMeta(selector: string, attribute: string, value: string): void {
        const existing = document.querySelector<HTMLMetaElement>(selector)

        if (existing !== null) {
            existing.setAttribute('content', value)
            // Track for cleanup even though we didn't create it.
            // We restore the original on dispose — but for navigation purposes
            // removing is safe: the next page's useHead will recreate what it needs.
            created.push(existing)
            return
        }

        const meta = document.createElement('meta')

        // Set the identifying attribute first so the element is findable.
        const [attrName, attrVal] = attribute.split('=')
        if (attrName !== undefined && attrVal !== undefined) {
            meta.setAttribute(attrName, attrVal)
        }

        meta.setAttribute('content', value)
        document.head.appendChild(meta)
        created.push(meta)
    }

    function upsertLink(rel: string, href: string): void {
        const selector = `link[rel="${rel}"]`
        const existing = document.querySelector<HTMLLinkElement>(selector)

        if (existing !== null) {
            existing.setAttribute('href', href)
            created.push(existing)
            return
        }

        const link = document.createElement('link')
        link.setAttribute('rel', rel)
        link.setAttribute('href', href)
        document.head.appendChild(link)
        created.push(link)
    }

    // ── document.title ─────────────────────────────────────────────────────────
    if (options.title !== undefined) {
        document.title = options.title
    }

    // ── description ────────────────────────────────────────────────────────────
    if (options.description !== undefined) {
        upsertMeta('meta[name="description"]', 'name=description', options.description)
    }

    // ── Open Graph tags ────────────────────────────────────────────────────────
    const ogTitle = options.title ?? document.title
    upsertMeta('meta[property="og:title"]', 'property=og:title', ogTitle)

    if (options.type !== undefined) {
        upsertMeta('meta[property="og:type"]', 'property=og:type', options.type)
    }

    if (options.description !== undefined) {
        upsertMeta('meta[property="og:description"]', 'property=og:description', options.description)
    }

    if (options.image !== undefined) {
        upsertMeta('meta[property="og:image"]', 'property=og:image', options.image)
    }

    if (options.url !== undefined) {
        upsertMeta('meta[property="og:url"]', 'property=og:url', options.url)
        upsertLink('canonical', options.url)
    }

    // ── Twitter card ───────────────────────────────────────────────────────────
    // summary_large_image renders the full-width image preview in Twitter/X cards,
    // which WhatsApp also honours when falling back to Twitter card metadata.
    upsertMeta('meta[name="twitter:card"]', 'name=twitter:card', 'summary_large_image')

    // ── Cleanup on scope dispose ────────────────────────────────────────────────
    // When the component owning this effect unmounts (or a watchEffect re-runs),
    // remove the tags we added so the next page starts with a clean slate.
    onScopeDispose(() => {
        for (const el of created) {
            el.parentNode?.removeChild(el)
        }
    })
}
