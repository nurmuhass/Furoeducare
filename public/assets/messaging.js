(() => {
    'use strict';
    const form = document.querySelector('[data-message-read]');
    if (!form || !window.fetch || !window.IntersectionObserver) return;
    const lastId = form.elements.namedItem('last_id').value;
    const lastMessage = document.querySelector(`[data-message-id="${lastId}"]`);
    if (!lastMessage) return;
    const feedback = form.querySelector('[data-read-feedback]');
    let visible = false;
    let pending = false;
    let complete = false;
    const markRead = async () => {
        if (!visible || document.visibilityState !== 'visible' || pending || complete) return;
        pending = true;
        form.setAttribute('aria-busy', 'true');
        feedback.textContent = 'Marking displayed messages as read…';
        try {
            const response = await fetch(form.dataset.readEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { 'Accept': 'application/json' },
                body: new FormData(form),
            });
            if (!response.ok) throw new Error('read failed');
            const result = await response.json();
            if (result.ok !== true) throw new Error('read failed');
            complete = true;
            feedback.textContent = 'Displayed messages marked as read.';
            observer.disconnect();
        } catch (_) {
            feedback.textContent = 'Use the button to mark displayed messages as read.';
        } finally {
            pending = false;
            form.removeAttribute('aria-busy');
        }
    };
    const observer = new IntersectionObserver(entries => {
        visible = entries.some(entry => entry.isIntersecting);
        markRead();
    }, { threshold: 0.1 });
    observer.observe(lastMessage);
    document.addEventListener('visibilitychange', markRead);
})();
