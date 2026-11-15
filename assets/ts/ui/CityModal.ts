/**
 * Модальное окно выбора города (открыть / закрыть / Escape).
 */
export class CityModal {
  public constructor(
    private readonly root: HTMLElement,
    private readonly openButtons: NodeListOf<Element>,
    private readonly closeButtons: NodeListOf<Element>,
    private readonly focusInput: HTMLInputElement | null,
  ) {}

  public bind(): void {
    this.openButtons.forEach((button) => {
      button.addEventListener('click', () => this.open());
    });
    this.closeButtons.forEach((button) => {
      button.addEventListener('click', () => this.close());
    });
    document.addEventListener('keydown', (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        this.close();
      }
    });
  }

  public open(): void {
    this.root.hidden = false;
    document.body.style.overflow = 'hidden';
    if (this.focusInput !== null) {
      this.focusInput.focus();
      this.focusInput.select();
    }
  }

  public close(): void {
    this.root.hidden = true;
    document.body.style.overflow = '';
  }
}
