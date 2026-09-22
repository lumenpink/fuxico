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
      <img src="" alt="Imagem Ampliada">
    </div>
  `;
  document.body.appendChild(lightbox);

  const lightboxImg = lightbox.querySelector('img');
  const closeBtn = lightbox.querySelector('.lightbox-close');

  const closeLightbox = () => {
    lightbox.classList.remove('active');
    lightboxImg.src = '';
  };

  closeBtn.addEventListener('click', closeLightbox);
  lightbox.addEventListener('click', (e) => {
    if (e.target === lightbox) closeLightbox();
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && lightbox.classList.contains('active')) {
      closeLightbox();
    }
  });

  // Attach click to all art wrappers and print images
  document.querySelectorAll('.art-img-wrapper img, .lightbox-trigger, .lightbox-trigger img').forEach(el => {
    el.style.cursor = 'zoom-in';
    el.addEventListener('click', (e) => {
      e.stopPropagation();
      const targetSrc = el.tagName === 'IMG' 
        ? el.src 
        : (el.getAttribute('data-full') || el.querySelector('img')?.src);
      if (targetSrc) {
        lightboxImg.src = targetSrc;
        lightbox.classList.add('active');
      }
    });
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
