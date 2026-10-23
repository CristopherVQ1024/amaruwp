import { ChangeDetectionStrategy, Component, computed, model } from '@angular/core';
import { DIAS, Horario, horarioVacio, validarHorario } from '../core/agenda';

@Component({
  selector: 'app-horario-editor',
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './horario-editor.html',
  styleUrl: './horario-editor.scss',
})
export class HorarioEditor {
  horario = model<Horario>(horarioVacio());

  readonly dias = DIAS;

  error = computed(() => validarHorario(this.horario()));
  todos = computed(() => this.horario().dias.length === DIAS.length);
  algunos = computed(() => this.horario().dias.length > 0 && !this.todos());

  marcado(dia: number): boolean {
    return this.horario().dias.includes(dia);
  }

  alternarDia(dia: number): void {
    this.horario.update((h) => ({ ...h, dias: h.dias.includes(dia) ? h.dias.filter((d) => d !== dia) : [...h.dias, dia] }));
  }

  alternarTodos(): void {
    this.horario.update((h) => ({ ...h, dias: this.todos() ? [] : DIAS.map((d) => d.valor) }));
  }

  cambiarHora(campo: 'desde' | 'hasta', valor: string): void {
    this.horario.update((h) => ({ ...h, [campo]: valor }));
  }
}
