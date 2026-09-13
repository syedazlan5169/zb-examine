document.addEventListener('DOMContentLoaded', () => {
    const triggers = Array.from(document.querySelectorAll('[data-evidence-lightbox-trigger]'));
    const lightbox = document.querySelector('[data-evidence-lightbox]');

    if (triggers.length === 0 || !lightbox) {
        return;
    }

    const image = lightbox.querySelector('[data-lightbox-image]');
    const closeButton = lightbox.querySelector('[data-lightbox-close]');
    const previousButton = lightbox.querySelector('[data-lightbox-previous]');
    const nextButton = lightbox.querySelector('[data-lightbox-next]');

    const photos = triggers.map((trigger) => ({
        src: trigger.dataset.lightboxSrc,
        alt: trigger.dataset.lightboxAlt ?? '',
    }));

    let currentIndex = 0;
    let previouslyFocused = null;

    const render = (index) => {
        currentIndex = index;
        image.src = photos[currentIndex].src;
        image.alt = photos[currentIndex].alt;
        previousButton.disabled = currentIndex === 0;
        nextButton.disabled = currentIndex === photos.length - 1;
        previousButton.hidden = photos.length < 2;
        nextButton.hidden = photos.length < 2;
    };

    const open = (index) => {
        previouslyFocused = document.activeElement;
        render(index);
        lightbox.hidden = false;
        document.body.classList.add('overflow-hidden');
        closeButton.focus();
    };

    const close = () => {
        lightbox.hidden = true;
        image.src = '';
        document.body.classList.remove('overflow-hidden');
        previouslyFocused?.focus();
    };

    const previous = () => {
        if (currentIndex > 0) {
            render(currentIndex - 1);
        }
    };

    const next = () => {
        if (currentIndex < photos.length - 1) {
            render(currentIndex + 1);
        }
    };

    triggers.forEach((trigger, index) => {
        trigger.addEventListener('click', () => open(index));
    });

    closeButton.addEventListener('click', close);
    previousButton.addEventListener('click', previous);
    nextButton.addEventListener('click', next);

    lightbox.addEventListener('click', (event) => {
        if (event.target === lightbox) {
            close();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (lightbox.hidden) {
            return;
        }

        if (event.key === 'Escape') {
            close();
        } else if (event.key === 'ArrowLeft') {
            previous();
        } else if (event.key === 'ArrowRight') {
            next();
        }
    });
});
