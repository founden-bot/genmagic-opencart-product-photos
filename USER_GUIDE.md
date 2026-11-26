# GenMagic AI Product Photos: User Guide

GenMagic AI Product Photos adds a panel to the OpenCart admin product page that makes a new photo of your product from its own main image, in a style you choose. You add the photos you like to the product's additional images.

The extension is free. Photos are paid from your own GenMagic balance.

## 1. Before you start

- OpenCart 4.0.2.x or 4.1.0.x, PHP 8.1 or later.
- A GenMagic account with an API key and some credit. Get a key at https://genmagic.co/get-api-key. Add credit at https://genmagic.co/pricing.

## 2. Install

1. Go to **Extensions > Installer**. Upload `genmagic.ocmod.zip`, then press the green Install button next to it.
2. Go to **Extensions > Extensions**. Choose **Modules** in the type list.
3. Find **GenMagic AI Product Photos** and press the green **+** to install it.

## 3. Add your API key and agree to send photos

1. On the same line press the blue pencil to open the settings.
2. Paste your key into **GenMagic API key**.
3. Tick **Send product photos to GenMagic**. Until this box is ticked, nothing is sent to GenMagic.
4. Switch **Status** on and press the save button.

## 4. Make a photo

1. Go to **Catalog > Products** and open a product. It needs a main image (saved with the product).
2. Scroll below the form to **GenMagic photos**.
3. Choose a **Style**:
   - **White studio**: clean white background, soft light.
   - **Lifestyle scene**: the product in a real-life setting.
   - **With a model**: a person using or wearing the product.
   - **Flat lay**: top-down view with props.
   - **Seasonal**: holiday setting.
4. Optionally write **Extra wishes** (up to 300 characters), for example "on a wooden table".
5. Press **Make photo** and wait. It can take up to a minute. When it is ready you see the photo, what it cost and your balance left.

Every style asks for the product itself to stay exactly as in your photo: same shape, colors, materials, label, logo and proportions. Always check the result before adding it.

## 5. Add it to the product

- Press **Add to additional images**. The photo is saved to the product at once and also appears in the Images tab. Save the product as usual if you changed other things.
- Tick **Also use it as the main image** only if you want the new photo to replace the current main image. Otherwise the main image stays as it is.
- Press **Discard** to drop the photo without adding it.

## 6. If something does not work

- "Nothing has been sent to GenMagic yet": tick the consent box in the settings.
- "Add your GenMagic API key": paste your key in the settings.
- "Your GenMagic balance is too low": add credit at genmagic.co/pricing.
- "This product has no main image": add a main image to the product and save it.
- The panel is not shown: check that Status is on and that your user group has the modify permission for this extension (System > Users > User Groups).

Questions: hello@genmagic.co
