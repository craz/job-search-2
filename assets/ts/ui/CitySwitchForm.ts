/**
 * Форма смены города: чипы Москва/Самара + переход на /?busy=set_city.
 */
export class CitySwitchForm {
  public constructor(
    private readonly form: HTMLFormElement,
    private readonly cityInput: HTMLInputElement,
    private readonly suggestButtons: NodeListOf<Element>,
  ) {}

  public bind(): void {
    this.suggestButtons.forEach((button) => {
      button.addEventListener('click', () => {
        const city = button.getAttribute('data-city') ?? '';
        this.cityInput.value = city;
        this.suggestButtons.forEach((el) => el.classList.remove('is-active'));
        button.classList.add('is-active');
      });
    });

    this.form.addEventListener('submit', (event: SubmitEvent) => {
      event.preventDefault();
      const city = this.cityInput.value.trim();
      if (city === '') {
        return;
      }
      const query = new URLSearchParams({
        busy: 'set_city',
        city,
      });
      window.location.href = `/?${query.toString()}`;
    });
  }
}
