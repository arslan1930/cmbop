/**
 * Shared article preview helpers: copy heading/article, download images, link meta UI.
 */
(function (window) {
  'use strict';

  function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, function (m) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m];
    });
  }

  async function copyText(text) {
    const value = String(text || '').trim();
    if (!value) throw new Error('Nothing to copy');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      await navigator.clipboard.writeText(value);
      return;
    }
    const ta = document.createElement('textarea');
    ta.value = value;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.left = '-9999px';
    document.body.appendChild(ta);
    ta.select();
    document.execCommand('copy');
    document.body.removeChild(ta);
  }

  async function copyHtml(html, plainFallback) {
    const rich = String(html || '');
    const plain = String(plainFallback || '').trim() || rich.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim();
    if (navigator.clipboard && window.ClipboardItem) {
      const item = new ClipboardItem({
        'text/html': new Blob([rich], { type: 'text/html' }),
        'text/plain': new Blob([plain], { type: 'text/plain' }),
      });
      await navigator.clipboard.write([item]);
      return;
    }
    await copyText(plain);
  }

  function toast(message, ok) {
    if (window.Swal) {
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: ok === false ? 'error' : 'success',
        title: message,
        showConfirmButton: false,
        timer: 2200,
      });
      return;
    }
    if (typeof window.showAppToast === 'function') {
      window.showAppToast(message);
      return;
    }
    console.log(message);
  }

  function extractHeading(root, fallbackTitle) {
    if (!root) return fallbackTitle || '';
    const heading = root.querySelector('h1, h2, h3');
    if (heading && heading.textContent.trim()) return heading.textContent.trim();
    return fallbackTitle || '';
  }

  function enhanceImages(root) {
    if (!root) return;
    root.querySelectorAll('img').forEach(function (img) {
      if (img.closest('.article-img-wrap')) return;
      const wrap = document.createElement('div');
      wrap.className = 'article-img-wrap';
      img.parentNode.insertBefore(wrap, img);
      wrap.appendChild(img);

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'article-img-download btn btn-sm btn-dark';
      btn.innerHTML = '<i class="fa fa-download me-1"></i>Download';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        downloadImage(img.getAttribute('src') || '', img.getAttribute('alt') || 'article-image');
      });
      wrap.appendChild(btn);
    });
  }

  async function downloadImage(src, nameHint) {
    if (!src) {
      toast('Image URL missing', false);
      return;
    }
    try {
      const res = await fetch(src, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('Failed to fetch image');
      const blob = await res.blob();
      const ext = (blob.type && blob.type.split('/')[1]) || 'jpg';
      const safe = String(nameHint || 'article-image').replace(/[^\w\-]+/g, '_').slice(0, 60);
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = safe + '.' + ext;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);
      toast('Image downloaded');
    } catch (err) {
      // Fallback: open in new tab
      window.open(src, '_blank', 'noopener');
      toast('Opened image — use Save As if download was blocked', false);
    }
  }

  function renderLinkRows(container, links, editable) {
    if (!container) return;
    const rows = Array.isArray(links) ? links : [];
    if (rows.length === 0) {
      container.innerHTML = '<p class="small text-muted mb-0">No links detected in this article.</p>';
      return;
    }
    container.innerHTML = rows.map(function (link, i) {
      const anchor = escapeHtml(link.anchor || '');
      const url = escapeHtml(link.url || '');
      if (!editable) {
        return '<div class="article-link-row border rounded-3 p-2 mb-2">' +
          '<div class="small text-muted">Anchor ' + (i + 1) + '</div>' +
          '<div class="fw-semibold">' + anchor + '</div>' +
          '<div class="small mt-1"><a href="' + url + '" target="_blank" rel="noopener noreferrer">' + url + '</a></div>' +
          '</div>';
      }
      return '<div class="article-link-row border rounded-3 p-2 mb-2" data-link-index="' + i + '">' +
        '<div class="row g-2">' +
        '<div class="col-md-5"><label class="form-label small mb-1">Anchor text</label>' +
        '<input type="text" class="form-control form-control-sm js-link-anchor" value="' + anchor + '" maxlength="120"></div>' +
        '<div class="col-md-7"><label class="form-label small mb-1">Target URL</label>' +
        '<input type="url" class="form-control form-control-sm js-link-url" value="' + url + '" placeholder="https://"></div>' +
        '</div></div>';
    }).join('');
  }

  function readLinkRows(container) {
    if (!container) return [];
    return Array.from(container.querySelectorAll('.article-link-row')).map(function (row) {
      const anchorEl = row.querySelector('.js-link-anchor');
      const urlEl = row.querySelector('.js-link-url');
      return {
        anchor: anchorEl ? anchorEl.value.trim() : '',
        url: urlEl ? urlEl.value.trim() : '',
      };
    }).filter(function (l) { return l.anchor || l.url; });
  }

  function extractLinksFromHtml(html) {
    const wrap = document.createElement('div');
    wrap.innerHTML = String(html || '');
    const seen = {};
    const out = [];
    wrap.querySelectorAll('a[href]').forEach(function (a) {
      const url = (a.getAttribute('href') || '').trim();
      if (!/^https:\/\//i.test(url)) return;
      const key = url.toLowerCase();
      if (seen[key]) return;
      seen[key] = true;
      const anchor = (a.textContent || '').trim() || url;
      out.push({ anchor: anchor.slice(0, 120), url: url });
    });
    return out;
  }

  window.ArticlePreviewTools = {
    escapeHtml: escapeHtml,
    copyText: copyText,
    copyHtml: copyHtml,
    toast: toast,
    extractHeading: extractHeading,
    enhanceImages: enhanceImages,
    downloadImage: downloadImage,
    renderLinkRows: renderLinkRows,
    readLinkRows: readLinkRows,
    extractLinksFromHtml: extractLinksFromHtml,
  };
})(window);
