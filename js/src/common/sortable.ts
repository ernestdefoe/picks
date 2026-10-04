/**
 * Drag-to-reorder for a list, on pointer events so it works the same with a
 * mouse, a finger and a pen.
 *
 * 🚨 Not the HTML5 drag-and-drop API: it does nothing on a phone, which is
 * where a lot of picks are made. The handle needs `touch-action: none` so the
 * browser does not take the gesture for a scroll.
 *
 * While dragging, the row follows the pointer and the rows it passes slide out
 * of its way, so the drop is previewed before it happens. Nothing is reordered
 * until the pointer is released; then `onDrop(from, to)` is called once, and the
 * caller reorders its own data and redraws.
 */
export interface DragOptions {
  /** The element holding the rows. */
  list: HTMLElement;
  /** Selects the draggable rows inside it, in order. */
  rowSelector: string;
  /** The index of the row being dragged. */
  from: number;
  onDrop: (from: number, to: number) => void;
}

export function startDrag(event: PointerEvent, opts: DragOptions): void {
  if (event.button !== undefined && event.button !== 0) return;

  const rows = Array.from(opts.list.querySelectorAll<HTMLElement>(opts.rowSelector));
  const dragged = rows[opts.from];
  if (!dragged || rows.length < 2) return;

  event.preventDefault();

  const rects = rows.map((r) => r.getBoundingClientRect());
  const gap = rects.length > 1 ? Math.max(0, rects[1].top - rects[0].bottom) : 0;
  const step = rects[opts.from].height + gap;
  const startY = event.clientY;
  const handle = event.currentTarget as HTMLElement | null;

  let to = opts.from;

  try {
    handle?.setPointerCapture(event.pointerId);
  } catch (e) {
    // A pointer that cannot be captured still drags while it stays over the handle.
  }

  dragged.classList.add('is-dragging');
  opts.list.classList.add('is-sorting');

  const move = (e: PointerEvent) => {
    const dy = e.clientY - startY;
    dragged.style.transform = `translateY(${dy}px)`;

    const centre = rects[opts.from].top + rects[opts.from].height / 2 + dy;

    // Where it would land: past every other row whose middle it has crossed.
    let target = 0;
    rects.forEach((r, i) => {
      if (i !== opts.from && centre > r.top + r.height / 2) target++;
    });
    to = target;

    rows.forEach((row, i) => {
      if (i === opts.from) return;
      let shift = 0;
      if (opts.from < to && i > opts.from && i <= to) shift = -step;
      if (opts.from > to && i >= to && i < opts.from) shift = step;
      row.style.transform = shift ? `translateY(${shift}px)` : '';
    });
  };

  const end = () => {
    handle?.removeEventListener('pointermove', move);
    handle?.removeEventListener('pointerup', end);
    handle?.removeEventListener('pointercancel', end);

    rows.forEach((row) => (row.style.transform = ''));
    dragged.classList.remove('is-dragging');
    opts.list.classList.remove('is-sorting');

    if (to !== opts.from) opts.onDrop(opts.from, to);
  };

  handle?.addEventListener('pointermove', move);
  handle?.addEventListener('pointerup', end);
  handle?.addEventListener('pointercancel', end);
}

/** The array with one item moved, for an onDrop callback. */
export function moveItem<T>(items: T[], from: number, to: number): T[] {
  const copy = items.slice();
  const [item] = copy.splice(from, 1);
  copy.splice(to, 0, item);
  return copy;
}
