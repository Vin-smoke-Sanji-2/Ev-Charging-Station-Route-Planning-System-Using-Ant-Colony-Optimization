/**
 * Plays a quick one-shot "pop" pulse on an element - a click
 * acknowledgement for toggle/action buttons (see the .pulse-once keyframes
 * in app.css). Safe to call repeatedly in quick succession: removing the
 * class and forcing a reflow before re-adding it lets the CSS animation
 * restart even on a rapid second click, which a plain classList.add()
 * alone wouldn't do - the browser sees the class is already present and
 * never restarts an animation already in progress.
 */
export function triggerPulse(el) {
    if (!el) return;
    el.classList.remove('pulse-once');
    void el.offsetWidth;
    el.classList.add('pulse-once');
}
