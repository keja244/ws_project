(() => {
    'use strict';

    const panel = document.getElementById('chatbotPanel');
    const launcher = document.getElementById('chatbotLauncher');
    const closeButton = document.getElementById('chatbotClose');
    const form = document.getElementById('chatbotForm');
    const input = document.getElementById('chatbotInput');
    const messages = document.getElementById('chatbotMessages');
    if (!panel || !launcher || !form || !input || !messages) return;

    const createConversationId = () => {
        if (window.crypto?.randomUUID) return window.crypto.randomUUID();
        const bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        bytes[6] = (bytes[6] & 0x0f) | 0x40;
        bytes[8] = (bytes[8] & 0x3f) | 0x80;
        const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
        return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
    };
    const conversationId = createConversationId();

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
    const addResults = (container, shows, moreShows = []) => {
        if (!Array.isArray(shows) || !shows.length) return;
        const cards = document.createElement('div');
        cards.className = 'chatbot__cards';
        const remaining = [...shows.slice(4), ...(Array.isArray(moreShows) ? moreShows : [])];
        const appendCard = (show) => {
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
        };
        shows.slice(0, 4).forEach(appendCard);
        container.appendChild(cards);

        if (remaining.length) {
            const more = document.createElement('button');
            more.className = 'chatbot__more';
            more.type = 'button';
            const updateMoreLabel = () => {
                more.textContent = `Lihat ${Math.min(5, remaining.length)} series lainnya`;
            };
            updateMoreLabel();
            more.addEventListener('click', () => {
                remaining.splice(0, 5).forEach(appendCard);
                if (remaining.length) updateMoreLabel();
                else more.remove();
                messages.scrollTop = messages.scrollHeight;
            });
            container.appendChild(more);
        }
        messages.scrollTop = messages.scrollHeight;
    };

    const addQuickReplies = (container, hasResults, quickReplies = []) => {
        const suggestions = Array.isArray(quickReplies) && quickReplies.length
            ? quickReplies
            : (hasResults
                ? ['Urutkan rating tertinggi', 'Urutkan terbaru', 'Mulai dari awal'].map((message) => ({ label: message, message }))
                : ['Cari serial comedy', 'Cari serial thriller', 'Mulai dari awal'].map((message) => ({ label: message, message })));
        const replies = document.createElement('div');
        replies.className = 'chatbot__quick-replies';
        suggestions.forEach((suggestion) => {
            const button = document.createElement('button');
            button.className = 'chatbot__quick-reply';
            button.type = 'button';
            button.textContent = suggestion.label;
            button.addEventListener('click', () => send(suggestion.message));
            replies.appendChild(button);
        });
        container.appendChild(replies);
    };

    const addFeedback = (container, responseId) => {
        if (!responseId) return;
        const controls = document.createElement('div');
        controls.className = 'chatbot__feedback';
        const status = document.createElement('span');
        status.setAttribute('aria-live', 'polite');

        const submitFeedback = async (helpful, reason = null) => {
            const buttons = controls.querySelectorAll('button');
            buttons.forEach((button) => { button.disabled = true; });
            try {
                const response = await fetch('/api/chat/feedback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ responseId, helpful, reason }),
                });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(data.message || 'Feedback belum berhasil dikirim. Coba lagi.');
                }
                controls.replaceChildren();
                status.textContent = 'Terima kasih atas feedback-mu.';
                controls.appendChild(status);
            } catch (error) {
                status.textContent = error.message || 'Feedback belum berhasil dikirim. Coba lagi.';
                controls.appendChild(status);
                buttons.forEach((button) => { button.disabled = false; });
            }
        };

        const helpful = document.createElement('button');
        helpful.type = 'button';
        helpful.textContent = '👍';
        helpful.setAttribute('aria-label', 'Jawaban membantu');
        helpful.addEventListener('click', () => submitFeedback(true));

        const notHelpful = document.createElement('button');
        notHelpful.type = 'button';
        notHelpful.textContent = '👎';
        notHelpful.setAttribute('aria-label', 'Jawaban tidak membantu');
        notHelpful.addEventListener('click', () => {
            controls.replaceChildren();
            const prompt = document.createElement('span');
            prompt.textContent = 'Apa yang perlu diperbaiki?';
            controls.appendChild(prompt);
            [
                ['Hasil tidak relevan', 'irrelevant'],
                ['Metadata salah', 'metadata'],
                ['Bot tidak mengerti', 'misunderstood'],
            ].forEach(([label, reason]) => {
                const option = document.createElement('button');
                option.type = 'button';
                option.textContent = label;
                option.addEventListener('click', () => submitFeedback(false, reason));
                controls.appendChild(option);
            });
        });

        controls.append(helpful, notHelpful);
        container.appendChild(controls);
    };

    const send = async (message) => {
        const trimmed = message.trim();
        if (!trimmed) return;
        addBubble(trimmed, 'user');
        input.value = '';
        form.querySelector('button[type="submit"]').disabled = true;
        messages.setAttribute('aria-busy', 'true');
        const pending = addBubble('Sebentar, aku cek katalog serial...', 'bot');
        const processingTimer = window.setTimeout(() => {
            pending.textContent = 'Masih memproses daftar rekomendasi...';
        }, 5000);

        try {
            const response = await fetch('/api/chat', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ message: trimmed, conversationId }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                throw new Error(response.status === 429
                    ? 'Pesan terlalu sering dikirim. Tunggu sebentar sebelum mencoba lagi.'
                    : data.message || 'Chat belum bisa memproses pertanyaan. Coba lagi sebentar.');
            }
            pending.textContent = data.answer;
            // §9.2: beri label jelas saat hasil adalah alternatif (filter dilonggarkan).
            if (data.alternatives) {
                const note = document.createElement('div');
                note.className = 'chatbot__note';
                note.textContent = 'Hasil di bawah adalah alternatif karena tidak semua kriteria terpenuhi.';
                pending.appendChild(note);
            }
            addResults(pending, data.shows, data.moreShows);
            addQuickReplies(pending, Array.isArray(data.shows) && data.shows.length > 0, data.quickReplies);
            addFeedback(pending, data.responseId);
        } catch (error) {
            pending.classList.add('chatbot__bubble--error');
            pending.textContent = error.message || 'Terjadi kendala saat menghubungi chatbot.';
        } finally {
            window.clearTimeout(processingTimer);
            messages.removeAttribute('aria-busy');
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