---
type: marketing
topic: facebook-ads
updated: 2026-07-18
---

# Facebook/Instagram ads playbook

## Infrastructure (live — rely on it)
- Meta Pixel + server-side Conversions API with event dedup (ViewContent,
  AddToCart, InitiateCheckout, Purchase, Lead); Purchase carries real client
  IPs and browser signals even though it's queued.
- **Catalog feed** for Commerce Manager: `/feed/meta.csv` (id, title,
  description, availability, price, links, images, brand). Brand comes from
  the store-name setting. Enables Advantage+ catalog/retargeting ads.
- Landing speed is ad-grade (sub-1s loads on mobile).

## Funnel structure
1. **Prospecting**: Advantage+ shopping or broad interest (women 20–45 BD,
   jewelry/fashion) → best sellers or a collection page, NOT the homepage.
2. **Retargeting**: catalog ads to ViewContent/AddToCart 7–14 days;
   creative = the exact pieces they viewed + COD trust line.
3. **Retention** is owned channels (push/SMS/offers) — don't pay Meta to
   reach existing members.

## Creative rules (from voice-and-tone)
- Hook line first ("The earrings she'll never take off").
- COD is the #1 objection-killer in BD: "Pay only when it's in your hands."
- Show the piece worn, in motion where possible; price on-screen for
  ৳1,000–2,000 pieces (qualifies clicks).
- One CTA: "Order now — cash on delivery."

## Measurement
- Pixel + CAPI dedup key is the order number; trust Events Manager dedup.
- Cross-check Meta-claimed purchases against the platform's own campaign
  analytics and order counts (Meta over-attributes).
- Watch delivery-side quality: fake/undeliverable COD orders from broad
  targeting → tighten audiences, consider requiring phone verification.
