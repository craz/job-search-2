import { LiveReload } from '../shared/LiveReload';
import { CityModal } from './CityModal';
import { CitySwitchForm } from './CitySwitchForm';
import { FlashBanner } from './FlashBanner';
import { ResumeTextModal } from './ResumeTextModal';
import { ScoreTenActions } from './ScoreTenActions';
import { ScoringProgressBar } from './ScoringProgressBar';
import { VacanciesModal } from './VacanciesModal';

/** Сборка UI главной страницы: модалки города/текста резюме/вакансий + форма. */
export class UiApp {
  public boot(): void {
    new LiveReload().boot();

    const flash = FlashBanner.fromDocument();
    if (flash !== null) {
      flash.bind();
    }

    const resumeText = ResumeTextModal.fromDocument();
    if (resumeText !== null) {
      resumeText.bind();
    }

    const vacancies = VacanciesModal.fromDocument();
    if (vacancies !== null) {
      vacancies.bind();
    }

    const scoringProgress = ScoringProgressBar.fromDocument();
    const scoreTen = ScoreTenActions.fromDocument(scoringProgress);
    if (scoreTen !== null) {
      scoreTen.bind();
    }

    const modalRoot = document.getElementById('city-modal');
    const cityInput = document.getElementById('city-input');
    const form = document.querySelector('form.js-set-city');

    if (
      !(modalRoot instanceof HTMLElement) ||
      !(cityInput instanceof HTMLInputElement) ||
      !(form instanceof HTMLFormElement)
    ) {
      return;
    }

    const modal = new CityModal(
      modalRoot,
      document.querySelectorAll('.js-open-city'),
      document.querySelectorAll('.js-close-city'),
      cityInput,
    );
    modal.bind();

    const switchForm = new CitySwitchForm(
      form,
      cityInput,
      document.querySelectorAll('.city-suggest'),
    );
    switchForm.bind();
  }
}
