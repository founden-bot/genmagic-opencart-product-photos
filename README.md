# GenMagic AI Product Photos for OpenCart 4

Make a new photo of a product from the product's own main image, in a style you choose, right on the admin product edit page. Add the ones you like to the product's additional images.

- Extension code: `genmagic` (module `genmagic_photos`)
- Version: 1.0.0
- License: MIT
- Requires: OpenCart 4.0.2.x or 4.1.0.x, PHP 8.1 or later with the curl extension
- Price: free. Photos are paid from your own prepaid GenMagic balance (see "Cost"). There is no OpenCart billing.

## What it does

On the product edit page a panel, **GenMagic photos**, lets you:

1. Pick a style: White studio, Lifestyle scene, With a model, Flat lay or Seasonal.
2. Optionally add a few words of your own (up to 300 characters).
3. Press **Make photo**. The saved main image of the product is sent to GenMagic, which returns a new photo. Every style asks for the product itself to stay exactly as it is (shape, colors, materials, label, logo, proportions).
4. Look at the result and press **Add to additional images**, or **Discard**.

An added photo goes to the product's additional images only. The main image is never replaced unless you tick **Also use it as the main image** before adding.

The main image must be a JPEG, PNG, WebP or GIF under 2.9 MB.

## Installation

1. In the admin go to **Extensions > Installer**, upload `genmagic.ocmod.zip` and press the green Install button next to it.
2. Go to **Extensions > Extensions**, choose the type **Modules**, find **GenMagic AI Product Photos** and press the green **+** (Install). This also registers the event that puts the panel on the product form and gives your user group permission to use the extension.
3. Press the blue pencil to open the settings.

To remove it, press the red **-** (Uninstall) in Extensions, then remove it under Extensions > Installer. Uninstalling removes the event and the saved settings. Photos you already added stay with the products (they are ordinary images in `image/catalog/genmagic/`).

The extension changes no OpenCart core file and creates no database table.

## Settings

1. Get an API key at https://genmagic.co/get-api-key and add credit at https://genmagic.co/pricing.
2. Open **Extensions > Extensions > Modules > GenMagic AI Product Photos**.
3. Paste the key into **GenMagic API key**.
4. Tick **Send product photos to GenMagic**. This is your explicit consent: until it is ticked, nothing is sent to GenMagic, not even the list of models, and the panel says so.
5. Switch **Status** on and save.

The key is kept in the store's settings table (OpenCart has no encrypted store for extension settings) and is only used from your server. It is never sent back to the browser: the field stays empty, and leaving it empty on a later save keeps the saved key.

### Permissions

The panel and its routes need the **modify** permission for `extension/genmagic/module/genmagic_photos`. Installing the module gives it to the user group that installed it. Give other groups access under **System > Users > User Groups**.

## Usage

1. Open **Catalog > Products** and edit a product that has a main image. Save a new product first.
2. Under the form, open the **GenMagic photos** panel, pick a style and press **Make photo**. It can take up to a minute.
3. Press **Add to additional images**. The photo is saved to the product at once and also appears in the Images tab. Save the product as usual if you changed anything else.

If your GenMagic balance is too low you see "Your GenMagic balance is too low for this photo. Add credit at genmagic.co/pricing and try again."

## Cost

Each photo is charged by GenMagic to your own GenMagic balance. The cost of the photo and the balance left are shown after each photo. The model picker shows an estimated price per model.

## Data sent to GenMagic and why

Only when you press **Make photo**, and only after you ticked the consent box:

| Data | Why |
| --- | --- |
| The main image file of that product | It is the picture the new photo is made from |
| The style text and your optional words | They say what kind of photo to make |
| Your API key (as a Bearer token) | It identifies your GenMagic account and balance |
| The chosen model name | It says which model makes the photo |

The list of models is loaded from GenMagic without your key (also only after consent) and kept for six hours. No customer, order or other store data is sent. The generated photo is downloaded from GenMagic by your server, only from GenMagic's own file hosts, and kept as a temporary file in `image/catalog/genmagic/tmp/` until you add it (files older than a day are removed). Calls go from your server, not from the admin's browser.

## How it works (for developers)

- `admin/controller/module/genmagic_photos.php`: settings page, the `models`, `generate` and `add` routes, install and uninstall (register and remove the event), and the `panel` event handler for `admin/view/catalog/product_form/after`.
- `admin/model/module/genmagic_photos.php`: the service (consent and key checks, source image, results, product_image row).
- `admin/model/module/genmagic_client.php`: the only code that talks to `https://genmagic.co/api/v1` (`GET /models?category=image`, `POST /images/generations`), with redirects handled by hand and downloads limited to `genmagic.co`, `axrsv1b9sa2ny4qv.public.blob.vercel-storage.com` and `tlwdqzfibvgektzgdfqa.supabase.co`.
- Every admin route checks the admin user token, the modify permission, and POST for the routes that change something.
- Because OpenCart rewrites a product's additional images from the form when you save it, a photo added from the panel is also put into the form's Images list, so saving the product afterwards keeps it.

`scripts/build-zip.sh` builds `dist/genmagic.ocmod.zip` (the file name sets the extension code, so keep it).

## Tests

`.github/workflows/install-test.yml` installs OpenCart 4.1.0.x (and 4.0.2.x as a report) with its CLI installer on a MySQL service, installs the package like the Extension Installer, and runs `.github/ci/unit.php`, `.github/ci/smoke.php` and `.github/ci/web-check.sh` with GenMagic mocked. When a step fails, the useful lines are printed as `::error::` annotations (at most 10).

## Support

hello@genmagic.co

## License

MIT. See `LICENSE`.
