function imageExists(url) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.onload = () => resolve(true);
    img.onerror = () => reject(false);
    img.src = url;
  });
}

imageExists("../../api/images/background_image.png")
  .then(() => {
    document.documentElement.style.setProperty(
      "--backgroundImage",
      "url('../../api/images/background_image.png')",
    );
  })
  .catch(() => {
    document.documentElement.style.setProperty(
      "--backgroundImage",
      "url('../images/MandaueBackground.jpg')",
    );
    console.warn(
      "Custom background image not found. Using default background.",
    );
  });
