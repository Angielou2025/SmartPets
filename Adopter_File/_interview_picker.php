<?php
/**
 * Pagpili ng interview: Google Calendar na icon -> pindutin -> lalabas ang
 * calendar (anumang Lunes-Biyernes na hindi pa lumilipas) -> pumili ng araw ->
 * ilagay ang sariling oras. Ang mga oras na kinuha na ng ibang adopter ay
 * ipinapakita at hindi na mapipili. Ang staff ang mag-a-Accept o Decline.
 *
 * Kailangan bago i-include:
 *   $pickerConfig - galing sa sp_interview_picker_config()
 *   $pickerDate   - (optional) naunang napiling petsa, 'Y-m-d'
 *   $pickerTime   - (optional) naunang oras, 'H:i'
 *   $pickerExclude- (optional) application_id ng sariling request na pinapalitan
 * Ipinapasa sa form: interview_date at interview_time.
 * Ang interview_picker.js ang nagpapagana nito (isama nang isang beses sa page).
 */
$pickerDate = $pickerDate ?? '';
$pickerTime = $pickerTime ?? '';
$pickerExclude = (int) ($pickerExclude ?? 0);
?>
<div class="ip-picker" data-config="<?php echo htmlspecialchars(json_encode($pickerConfig)); ?>"<?php echo $pickerExclude > 0 ? ' data-exclude="' . $pickerExclude . '"' : ''; ?>>
    <button type="button" class="ip-toggle" aria-expanded="false">
        <span class="gcal-icon" aria-hidden="true">
            <span class="gcal-top"><?php echo strtoupper(date('M')); ?></span>
            <span class="gcal-num"><?php echo date('j'); ?></span>
        </span>
        <span class="ip-toggle-text">
            <strong class="ip-toggle-title">Choose your interview date</strong>
            <small class="ip-toggle-sub">Tap the calendar to pick a day when City Hall is open</small>
        </span>
        <i class="fa-solid fa-chevron-down ip-chev" aria-hidden="true"></i>
    </button>

    <div class="ip-panel" hidden>
        <div class="ip-panel-head">
            <span>Select available date</span>
        </div>
        <!-- Malapad na screen: calendar sa kaliwa, oras sa kanan -->
        <div class="ip-body">
        <div class="ip-cal-col">
            <div class="ip-cal"></div>
            <div class="ip-legend">
                <span><i class="ip-dot ip-dot-open"></i> Available</span>
                <span><i class="ip-dot ip-dot-off"></i> Not available</span>
                <span><i class="ip-dot ip-dot-special"></i> Special day</span>
            </div>
        </div>

        <div class="ip-side">
        <div class="ip-time-empty">
            <i class="fa-regular fa-hand-pointer"></i>
            <span>Choose a date on the calendar, then enter your time here.</span>
        </div>
        <!-- Napindot ang special day (hal. Dec 25 - Christmas Day): sarado ang City Hall -->
        <div class="ip-special" role="alert" hidden>
            <div class="ip-special-badge">
                <span class="ip-special-mon"></span>
                <span class="ip-special-day"></span>
            </div>
            <span class="ip-special-tag"><i class="fa-solid fa-star"></i> Special day</span>
            <strong class="ip-special-name"></strong>
            <p class="ip-special-text"></p>
        </div>
        <div class="ip-time" hidden>
            <div class="ip-time-date"></div>
            <p class="ip-hours"></p>
            <div class="ip-taken" hidden>
                <span class="ip-taken-title"><i class="fa-solid fa-ban"></i> Times not available</span>
                <div class="ip-taken-list"></div>
            </div>
            <label class="ip-time-label">Your preferred time</label>
            <?php // Mga oras na puwede lang ang makikita: AM/PM -> oras -> minuto ?>
            <div class="ip-tfield">
                <button type="button" class="ip-tbtn" aria-haspopup="listbox" aria-expanded="false">
                    <span class="ip-tval">--:-- --</span>
                    <i class="fa-regular fa-clock" aria-hidden="true"></i>
                </button>
                <div class="ip-tpop" hidden>
                    <div class="ip-tcol ip-col-h" role="listbox" aria-label="Hour"></div>
                    <div class="ip-tcol ip-col-m" role="listbox" aria-label="Minutes"></div>
                    <div class="ip-tcol ip-col-p" role="listbox" aria-label="AM or PM"></div>
                </div>
            </div>
            <input type="time" class="ip-time-input" step="900" value="<?php echo htmlspecialchars($pickerTime); ?>" hidden tabindex="-1">
        </div>
        <?php // Nasa labas ng .ip-time: nakikita rin kahit wala pang napiling petsa ?>
        <p class="ip-error" role="alert" hidden></p>
        </div>
        </div>
    </div>

    <input type="hidden" name="interview_date" class="ip-date-value" value="<?php echo htmlspecialchars($pickerDate); ?>">
    <input type="hidden" name="interview_time" class="ip-time-value" value="<?php echo htmlspecialchars($pickerTime); ?>">
</div>
