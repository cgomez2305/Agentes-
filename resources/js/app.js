// Mantiene el hilo de mensajes pegado al final cuando llegan mensajes nuevos,
// salvo que la persona haya subido a leer el historial.
let thread = null;
let conversationId = null;
let stickToBottom = true;

const nearBottom = (el) => el.scrollHeight - el.scrollTop - el.clientHeight < 120;

const syncThread = () => {
    const el = document.getElementById('thread');

    if (!el) {
        thread = null;
        return;
    }

    if (el !== thread) {
        thread = el;
        el.addEventListener('scroll', () => { stickToBottom = nearBottom(el); }, { passive: true });
    }

    // Al abrir otra conversación (Livewire reutiliza el mismo elemento) se empieza por el final.
    if (el.dataset.conversation !== conversationId) {
        conversationId = el.dataset.conversation;
        stickToBottom = true;
    }

    if (stickToBottom) {
        el.scrollTop = el.scrollHeight;
    }
};

new MutationObserver(syncThread).observe(document.documentElement, { childList: true, subtree: true });
document.addEventListener('DOMContentLoaded', syncThread);
