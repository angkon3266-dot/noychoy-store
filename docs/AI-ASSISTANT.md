# The chat assistant

A floating "Ask us anything" bubble on every storefront page. Customers ask in
English or Bangla; the assistant answers in the language they used, can search
the live catalogue, and can report an order's status when given the order
number **and** the phone used on it.

## Switching it on

Admin → **System Config → AI assistant**:

| Field | What it does |
|---|---|
| Show the assistant on the storefront | The master switch. Off, and neither the bubble nor the endpoint respond. |
| OpenAI API key | Stored **encrypted** (`system_configs.is_encrypted`), masked in the UI, never logged. |
| Model | `gpt-5-mini` by default. Any chat-completions model works; reasoning models get `reasoning_effort: low`. |
| Greeting | The first bubble. Defaults to a bilingual welcome. |
| Extra instructions | Free text appended to the system prompt — house rules, current promotions, tone. |

Use **Test connection** after pasting the key: it calls the model endpoint,
which proves both the key and the model name for free.

## What it knows, and where that comes from

Everything is assembled per request in `App\Services\Ai\AssistantService::systemPrompt()`:

- Store name, phone, email, WhatsApp — Admin → Settings / Appearance.
- Delivery charges, the free-delivery threshold, delivery-day windows — the
  same helpers the checkout uses (`free_shipping_threshold()`, the theme's
  `delivery_days_*`).
- Cash-on-delivery only; the membership offer (`chrome.membership` facts);
  the reward-ladder promise (`GiftLadder::pdpBadge()`); active collections
  and top-level categories.
- The refund and terms pages (stripped of HTML, capped), and the
  `knowledge/policies/*.md` + `knowledge/support/faq.md` files **minus any
  line containing `[CONFIRM`** — an unresolved owner question never reaches
  a customer.

Prices, stock and order status are **never** in the prompt. The model has two
tools and is instructed to use only them:

| Tool | Backed by | Returns |
|---|---|---|
| `search_products(query, max_price?)` | `Product::published()->search()` + `ProductSearch::orderByRelevance()` (+ `didYouMean()` fallback) | up to 6 pieces: name, price, member price, availability, URL. The same rows come back to the widget as product cards. |
| `order_status(order_number, phone)` | the exact query the public Track page uses: order number **and** canonical phone must both match | status label, last three updates, courier status + tracking code, track URL. No items, address, totals or staff notes. |
| `my_orders()` — **signed-in customers only** | `$customer->orders()` (ownership proven by the session) | the last five orders with items, totals, status, courier tracking and account links. Guests never see this tool. |

A signed-in customer also gets a `CUSTOMER` block in the prompt: first name,
points balance and tier, the pieces they loved (as gift-idea hints), and their
last three order numbers — so "where is my order?" needs no number typed.

## Voice

The assistant is named **{store name} AI Assistant** and briefed to be warm and
a little playful, "like the best attendant in a jewelry shop". It mirrors the
customer's register: Bangla script → Bangla, Banglish ("delivery charge
koto?") → Banglish ("Beshi na sir, matro ৳85 …"), English → English. The
worked examples in the prompt are generated from the live delivery numbers,
so the style guide can never contradict the facts. The owner can add house
rules in **Extra instructions**.

It makes the first move. The panel opens with a question ("Hi Rima! … How
can I help you today?" — the first name when the customer is signed in), four
starter chips in the visitor's language, and a once-per-session teaser beside
the launcher six seconds into the visit ("Kichu lagbe? 👋 Ask me anything" /
"কিছু লাগবে? 👋 আমাকে জিজ্ঞেস করুন"). The teaser never shows on the checkout
form or once a conversation exists; it is placed from the launcher's live box,
so it sits correctly on both the React and the Blade floating stacks.
Signed-in members get their name, tier, points and last three orders in the
prompt, and the `my_orders` tool.

## The endpoint

`POST /assistant/chat` (web group: session + CSRF) — body
`{ messages: [{role, content}…], page }`, at most 12 turns of 1,200
characters, the last one from the customer. Responses:

| Status | Meaning |
|---|---|
| 200 | `{ ok: true, reply, products[] }` |
| 422 | validation |
| 429 | the `assistant` limiter: 15/min, 100/hour, 300/day per IP |
| 502 | OpenAI failed (bad key, quota, outage) — `reply` is the friendly fallback with the store phone |
| 503 | the assistant is switched off |

Nothing the customer types is stored or logged. The transcript lives in the
browser's `sessionStorage` (`noychat.v1`) and travels with each request.

## Where the code lives

- `app/Services/Ai/AssistantService.php` — prompt, tools, OpenAI call.
- `app/Http/Controllers/Shop/AssistantController.php` — validation + the route.
- `resources/views/partials/ai-chat.blade.php` — the panel: a self-mounting
  vanilla-JS island included at the end of **both** root views
  (`inertia.blade.php` and `layouts/shop.blade.php`), so it survives Inertia
  navigations and works on Blade landing pages. Exposes `window.NoyChat`.
- The launcher button sits in the floating stacks: `FloatingStack.jsx`
  (React) and the matching block in `layouts/shop.blade.php`. Both read the
  same `AssistantService::enabled()`.
- `config/services.php` → `openai`, overridden at runtime by System Config.
- `tests/Feature/AssistantChatTest.php` — OpenAI is faked; the tests pin the
  off-switch, the grounding facts, the tool round-trip, the phone check on
  orders, the size limits, the clean 502, and the meter.

## Bangla

Writing to the assistant in Bengali script sets the visitor's language
(`App\Support\Locale`: the `lang` cookie, and the member record for members),
which then drives the greeting, the chips, the header strip and the rest of
the Bangla chrome copy site-wide. The footer toggle does the same by hand.

The model handles Bangla itself. The widget sets `lang="bn"` on Bengali
bubbles and falls back to system Bengali faces (Noto Sans Bengali, Nirmala
UI, Bangla Sangam MN) because the self-hosted webfonts ship Latin subsets
only. If Bangla renders poorly on a device, that is the fix: add a Bengali
subset to the font build rather than a Google Fonts link (`font-src` is
`'self'`).
