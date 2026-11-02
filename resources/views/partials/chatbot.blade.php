<section class="chatbot" id="chatbot">
    <button class="chatbot__launcher" id="chatbotLauncher" type="button" aria-expanded="false" aria-controls="chatbotPanel">
        <span aria-hidden="true">✦</span> Ask ITV
    </button>
    <div class="chatbot__panel" id="chatbotPanel" role="dialog" aria-label="ITV series assistant" hidden>
        <header class="chatbot__head">
            <div>
                <strong>Serial consultant</strong>
                <span>Ask about shows, cast, or filters</span>
            </div>
            <button type="button" id="chatbotClose" aria-label="Close chat">×</button>
        </header>
        <div class="chatbot__messages" id="chatbotMessages" aria-live="polite">
            <div class="chatbot__bubble chatbot__bubble--bot">Hai! Lagi bingung pilih serial, cari info pemeran, atau mau pakai filter? Tanya saja.</div>
        </div>
        <form class="chatbot__form" id="chatbotForm">
            <label class="sr-only" for="chatbotInput">Tulis pertanyaan</label>
            <input id="chatbotInput" name="message" maxlength="500" placeholder="Tanya soal serial TV..." autocomplete="off" required>
            <button type="submit" aria-label="Send message">Send</button>
        </form>
    </div>
</section>
