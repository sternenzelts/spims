// The withdrawal and replenishment stations are used with a keyboard and a badge reader alone
// (design §5.1). A Livewire re-render can remove the element that currently has focus — the badge
// field once a scan succeeds, a "match" button once its part is added, a dialog's whole subtree
// once it opens — and the browser then drops focus to <body>, breaking the keyboard-only flow.
//
// A Livewire action that knows what the custodian should do next dispatches a `station-focus`
// browser event naming a field by its `data-focus` attribute. This listens for that event, once,
// for the whole page: it is plain DOM, not Alpine's `$refs`, because the station's dialogs run in
// their own nested Alpine scope and a ref declared inside one is not visible from the page root.
document.addEventListener('station-focus', (event) => {
    const field = event.detail?.field;

    if (!field) {
        return;
    }

    requestAnimationFrame(() => {
        document.querySelector(`[data-focus="${field}"]`)?.focus();
    });
});
