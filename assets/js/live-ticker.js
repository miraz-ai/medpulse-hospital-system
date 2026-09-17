/**
 * MedPulse Executive Dashboard - Live Event Telemetry Ticker
 * Seamless vertical auto-sliding event ticker with pause-on-hover.
 */
document.addEventListener('DOMContentLoaded', () => {
  const tickerBar = document.getElementById('telemetryTicker');
  const tickerTrack = document.getElementById('tickerTrack');

  if (!tickerBar || !tickerTrack) return;

  const originalItems = tickerTrack.querySelectorAll('.ticker-item');
  const count = originalItems.length;
  if (count <= 1) return;

  // Clone the first item to enable a seamless infinite upward scroll
  const firstClone = originalItems[0].cloneNode(true);
  firstClone.setAttribute('aria-hidden', 'true');
  tickerTrack.appendChild(firstClone);

  let currentIndex = 0;
  let timer = null;
  let isPaused = false;
  const slideIntervalMs = 4000;
  const itemHeight = 40; // Synchronized with CSS .ticker-item height

  function slideNext() {
    if (isPaused) return;

    currentIndex++;
    tickerTrack.classList.remove('no-transition');
    tickerTrack.style.transform = `translateY(-${currentIndex * itemHeight}px)`;

    // When we transition to the clone at the end, reset seamlessly to the original first item
    if (currentIndex === count) {
      tickerTrack.addEventListener('transitionend', handleTransitionEnd, { once: true });
    }
  }

  function handleTransitionEnd() {
    if (currentIndex === count) {
      tickerTrack.classList.add('no-transition');
      currentIndex = 0;
      tickerTrack.style.transform = 'translateY(0px)';
      // Force reflow so removing 'no-transition' later takes effect
      void tickerTrack.offsetHeight;
    }
  }

  function startAutoSlide() {
    stopAutoSlide();
    timer = setInterval(slideNext, slideIntervalMs);
  }

  function stopAutoSlide() {
    if (timer) {
      clearInterval(timer);
      timer = null;
    }
  }

  // Hover Behavior: Pause sliding when hovering over the ticker bar
  tickerBar.addEventListener('mouseenter', () => {
    isPaused = true;
    stopAutoSlide();
  });

  tickerBar.addEventListener('mouseleave', () => {
    isPaused = false;
    startAutoSlide();
  });

  // Tab Visibility: Pause when browser tab is inactive to prevent animation queueing
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
      stopAutoSlide();
    } else {
      if (!isPaused) {
        startAutoSlide();
      }
    }
  });

  // Start the auto-slide
  startAutoSlide();
});
