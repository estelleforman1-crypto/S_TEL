# Shop images

Put image files here, then run:

```bash
npm run images
```

Refresh the browser to see them. The **file name** decides where an image goes:

| File | Where it shows | Best shape |
|---|---|---|
| `pages/home.jpg` | Opening image on the home page | Wide, 16:9 (e.g. 2400×1350) |
| `pages/about.jpg` | Maker portrait (home page and About page) | Upright, 4:5 |
| `categories/rings.jpg`, `earrings.jpg`, `necklaces.jpg` | Category panels on the home page | Wide, 16:9 (e.g. 1600×1000) |
| `products/<product-slug>.jpg` | Product main image | Upright, 4:5 (e.g. 1200×1500) |
| `products/<product-slug>-2.jpg`, `-3.jpg`, ... | Extra product images, in that order | Upright, 4:5 |

Demo product slugs: `demo-signet-ring`, `demo-one-of-a-kind-pendant`, `demo-hoop-earrings`, `demo-band-ring`, `demo-chain-necklace`. A product's slug is the last part of its address (`/product/demo-band-ring/`).

- `.jpg`, `.jpeg`, `.png` and `.webp` work. Use one file per name.
- Replace a file and run `npm run images` again to swap the image. Delete a file to bring back the grey DEMO placeholder.
- Images are cropped to fill their frame, so keep the subject near the center.

This folder feeds the **development shop** only. On the live site, photos are uploaded in WordPress admin (Products > edit product > Product image), because products are content, not code.
