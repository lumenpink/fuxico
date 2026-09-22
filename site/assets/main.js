// =============================================================================
// FUXICO — Site Client Interactivity & Lightbox
// https://fuxico.2lp.in
// =============================================================================

document.addEventListener('DOMContentLoaded', () => {
  // 1. Copy Buttons for Code & Terminal blocks
  document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', async () => {
      const targetId = btn.getAttribute('data-target');
      const textToCopy = targetId 
        ? document.getElementById(targetId)?.innerText 
        : btn.closest('.terminal-window')?.querySelector('pre')?.innerText;

      if (textToCopy) {
        try {
          await navigator.clipboard.writeText(textToCopy.trim());
          const originalText = btn.innerText;
          btn.innerText = 'COPIADO! ✨';
          btn.style.color = '#e2f835';
          setTimeout(() => {
            btn.innerText = originalText;
            btn.style.color = '';
          }, 2000);
        } catch (err) {
          console.error('Falha ao copiar:', err);
        }
      }
    });
  });

  // 2. Lightbox Modal for Art and Prints
  const lightbox = document.createElement('div');
  lightbox.className = 'lightbox';
  lightbox.innerHTML = `
    <div class="lightbox-content">
      <button class="lightbox-close" aria-label="Fechar">&times;</button>
      <img alt="Imagem Ampliada">
    </div>
  `;
  document.body.appendChild(lightbox);

  const lightboxImg = lightbox.querySelector('img');
  const closeBtn = lightbox.querySelector('.lightbox-close');

  const closeLightbox = () => {
    lightbox.classList.remove('active');
    lightboxImg.removeAttribute('src');
  };

  closeBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    closeLightbox();
  });

  lightbox.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lightbox.classList.contains('active')) {
      closeLightbox();
    }
  });

  // Global delegation for all art, prints, and lightbox triggers
  document.addEventListener('click', (e) => {
    const trigger = e.target.closest('.lightbox-trigger, .art-img-wrapper, .hero-brand-banner');
    if (!trigger) return;

    const img = trigger.tagName === 'IMG' ? trigger : trigger.querySelector('img');
    if (!img) return;

    e.preventDefault();
    e.stopPropagation();

    // Browser-resolved absolute URL from live rendered img
    const resolvedUrl = img.currentSrc || img.src;
    if (resolvedUrl) {
      lightboxImg.src = resolvedUrl;
      lightboxImg.alt = img.alt || 'Imagem Ampliada';
      lightbox.classList.add('active');
    }
  });


  // 3. Language preference persistence
  document.querySelectorAll('.lang-btn').forEach(btn => {
    btn.addEventListener('click', (e) => {
      const lang = btn.textContent.trim().toLowerCase();
      if (lang === 'pt') {
        localStorage.setItem('fuxico_lang', 'pt');
      } else if (lang === 'en') {
        localStorage.setItem('fuxico_lang', 'en');
      }
    });
  });
});
