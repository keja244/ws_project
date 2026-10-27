(() => {
    'use strict';

    const panel = document.getElementById('chatbotPanel');
    const launcher = document.getElementById('chatbotLauncher');
    const closeButton = document.getElementById('chatbotClose');
    const form = document.getElementById('chatbotForm');
    const input = document.getElementById('chatbotInput');
    const messages = document.getElementById('chatbotMessages');
    if (!panel || !launcher || !form || !input || !messages) return;

    const open = (isOpen) => {
        panel.hidden = !isOpen;
        launcher.setAttribute('aria-expanded', String(isOpen));
        if (isOpen) input.focus();
    };
    launcher.addEventListener('click', () => open(panel.hidden));
    closeButton?.addEventListener('click', () => open(false));

    const addBubble = (text, kind = 'bot') => {
        const bubble = document.createElement('div');
        bubble.className = `chatbot__bubble chatbot__bubble--${kind}`;
        bubble.textContent = text;
        messages.appendChild(bubble);
        messages.scrollTop = messages.scrollHeight;
        return bubble;
    };
    const addResults = (container, shows) => {
        if (!Array.isArray(shows) || !shows.length) return;
        const cards = document.createElement('div');
        cards.className = 'chatbot__cards';
        shows.forEach((show) => {
            const link = document.createElement('a');
            link.className = 'chatbot__card';
            link.href = `/detail/${encodeURIComponent(show.id)}`;
            if (show.image) {
                const image = document.createElement('img');
                image.src = show.image;
                image.alt = `Poster ${show.name}`;
                image.loading = 'lazy';
                image.referrerPolicy = 'no-referrer';
                image.onerror = () => image.remove();
                link.appendChild(image);
            } else {
                const fallback = document.createElement('span');
                fallback.className = 'chatbot__poster-fallback';
                fallback.textContent = 'ITV';
                link.appendChild(fallback);
            }
            const details = document.createElement('span');
            const title = document.createElement('strong');
            title.textContent = show.name;
            const meta = document.createElement('small');
            meta.textContent = [show.year, show.genre, show.rating ? `★ ${show.rating.toFixed(1)}` : '', show.contentRating ? `Usia ${show.contentRating}` : '', show.reason].filter(Boolean).join(' · ');
            details.append(title, meta);
            link.appendChild(details);
            cards.appendChild(link);
        });
        container.appendChild(cards);
        messages.scrollTop = messages.scrollHeight;
    };

    const send = async (message) => {
        const trimmed = message.trim();
        if (!trimmed) return;
        addBubble(trimmed, 'user');
        input.value = '';
        form.querySelector('button[type="submit"]').disabled = true;
        const pending = addBubble('Sebentar, aku cek katalog serial...', 'bot');

        try {
            const response = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ message: trimmed }),
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Chat belum bisa memproses pertanyaan. Coba lagi sebentar.');
            pending.textContent = data.answer;
            addResults(pending, data.shows);
        } catch (error) {
            pending.classList.add('chatbot__bubble--error');
            pending.textContent = error.message || 'Terjadi kendala saat menghubungi chatbot.';
        } finally {
            form.querySelector('button[type="submit"]').disabled = false;
            input.focus();
            messages.scrollTop = messages.scrollHeight;
        }
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        send(input.value);
    });
})();
