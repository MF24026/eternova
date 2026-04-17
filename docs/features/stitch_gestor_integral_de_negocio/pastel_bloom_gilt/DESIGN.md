# Design System: The Ethereal Boutique

## 1. Overview & Creative North Star: "The Digital Florist’s Atelier"
The Creative North Star for this design system is **The Digital Florist’s Atelier**. We are moving away from the rigid, boxy constraints of traditional e-commerce. Instead, we treat the screen as a curated workspace—a physical desk where fine paper, silk ribbons, and fresh petals overlap. 

To achieve a "Dreamy yet Organized" feel, the layout must embrace **Intentional Asymmetry**. Do not feel forced to align every image to a hard grid; allow high-resolution floral photography to "bleed" off-center or overlap with typography. The goal is a high-end editorial experience that feels whispered, not shouted.

---

## 2. Colors: Tonal Depth & The "No-Line" Rule
The palette utilizes soft pastel pinks and muted purples to evoke sophistication. However, the secret to a premium feel lies in how these colors are layered, not just applied.

### The "No-Line" Rule
**Explicit Instruction:** Prohibit the use of 1px solid borders for sectioning or containment. Boundaries must be defined solely through background color shifts.
*   **Implementation:** A section using `surface-container-low` (#fff0f2) should sit directly against a `surface` (#fff8f7) background. This creates a soft "edge" that feels organic rather than mechanical.

### Surface Hierarchy & Nesting
Treat the UI as a series of physical layers. Use the surface-container tiers to define importance:
*   **Base Layer:** `surface` (#fff8f7)
*   **Secondary Content:** `surface-container-low` (#fff0f2)
*   **Interactive Cards:** `surface-container-highest` (#f4dde0)
*   **Floating Elements:** `surface-container-lowest` (#ffffff)

### The "Glass & Gradient" Rule
To elevate CTAs, use a **Signature Gradient** transitioning from `primary` (#7c545d) to `primary-container` (#f8c4cf) at a 135-degree angle. For floating navigation or over-image overlays, use **Glassmorphism**: apply `surface` at 70% opacity with a `24px` backdrop-blur.

---

## 3. Typography: Editorial Sophistication
We pair a high-contrast serif with a modern, breathable sans-serif to create an "Editorial Boutique" aesthetic.

*   **Display & Headlines (Noto Serif):** These are your "Hero" elements. Use `display-lg` for impactful storytelling. Ensure tight tracking (-2%) on large headlines to maintain a bespoke, printed look.
*   **Body & Titles (Plus Jakarta Sans):** Chosen for its geometric clarity and generous x-height. 
    *   **Body-lg:** Use for product descriptions to ensure a "Premium" reading experience.
    *   **Label-md:** Always uppercase with `0.05em` letter spacing when used for categories or small UI tags to provide a "Gilt" feel.

---

## 4. Elevation & Depth: Tonal Layering
Traditional drop shadows are too heavy for a "Dreamy" brand. We utilize light and color to create lift.

*   **The Layering Principle:** Achieve depth by stacking tokens. Place a `surface-container-lowest` card on a `surface-container-low` section. This creates a "Paper-on-Linen" effect.
*   **Ambient Shadows:** When a shadow is necessary (e.g., a floating Cart drawer), use an **extra-diffused** shadow: `0px 12px 32px rgba(61, 47, 50, 0.06)`. The tint is derived from `on-surface` to ensure the shadow looks like natural light hitting a physical object.
*   **The "Ghost Border" Fallback:** If accessibility requires a border, use `outline-variant` (#c2adb0) at **15% opacity**. High-contrast borders are strictly forbidden.

---

## 5. Components: Light & Airy Primitives

### Buttons
*   **Primary:** A soft gradient from `primary` to `primary-dim`. Roundedness: `full` (pill). Text should be `on-primary` (#fff7f7).
*   **Secondary:** No background. Use a `title-sm` weight with a subtle `primary` underline that expands on hover.
*   **Tertiary:** `surface-container-high` background with `on-surface` text. No border.

### Input Fields
*   **Styling:** Forgo the "box." Use a `surface-container-low` background with a `xl` (1.5rem) corner radius. 
*   **Interaction:** On focus, transition the background to `surface-container-highest`. Use `primary` for the cursor and label.

### Cards (Boutique Gallery)
*   **Rule:** Forbid divider lines. Use `xl` (1.5rem) rounded corners.
*   **Layout:** Image should be the hero, with typography nested in a `surface-container-lowest` area that overlaps the bottom 10% of the image.

### Signature Component: The "Bloom" Chip
*   Used for flower types or gift categories. Use `secondary-container` (#eddcff) with `on-secondary-container` (#5a4b71) text. These should feel like small, silk ribbons—softly rounded and tactile.

---

## 6. Do’s and Don’ts

### Do:
*   **Do** use generous white space (Spacing 32px+) between sections to allow the design to "breathe."
*   **Do** use `surface-tint` (#7c545d) at low opacities (5-8%) as a subtle overlay on images to harmonize them with the brand palette.
*   **Do** favor "Center-Aligned" typography for luxury storytelling sections.

### Don’t:
*   **Don’t** use pure black (#000000) for text. Use `on-surface` (#3d2f32) to keep the contrast soft and elegant.
*   **Don’t** use hard `0px` or `sm` corners. Everything must feel soft to the touch; stick to `lg` (1rem) or `xl` (1.5rem).
*   **Don’t** use standard "Drop Shadows." If it doesn't look like an ambient glow, it's too heavy.

### Accessibility Note:
While the palette is pastel, ensure all functional text (Body and Labels) uses `on-surface` (#3d2f32) or `primary` (#7c545d) to maintain a high contrast ratio against the light surface containers.