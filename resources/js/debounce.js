// A pure, page-agnostic utility - unlike this project's usual "copy the
// well-tested shape per page" precedent for page-shaped rendering/business
// logic (e.g. verificationBadge()), this has zero DOM/state coupling, so
// sharing it isn't a divergence risk the way sharing page logic would be.
export function debounce(fn, delay) {
    let timer = null;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
}
