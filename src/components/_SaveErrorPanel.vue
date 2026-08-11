<template>
    <div class="fsnip_error_panel">
        <div class="fsnip_error_head">
            <span class="dashicons dashicons-warning"></span>
            <div class="fsnip_error_head_text">
                <h4>{{ details.title || $t('The snippet could not be saved') }}</h4>
                <span v-if="details.line" class="fsnip_error_line">{{ $t('Line') }} {{ details.line }}</span>
            </div>
        </div>

        <!--
            What PHP actually said, directly under the headline and never folded away.
            It is the one line that identifies the problem - the name it could not find,
            the token it did not expect - and everything below it is context for this.
        -->
        <pre v-if="showRaw" class="fsnip_error_raw">{{ details.raw }}</pre>

        <p v-if="details.reason" class="fsnip_error_reason">{{ details.reason }}</p>

        <div v-if="details.fix" class="fsnip_error_fix">
            <strong>{{ $t('How to fix it') }}</strong>
            <p>{{ details.fix }}</p>
            <pre v-if="details.example" class="fsnip_error_example">{{ details.example }}</pre>
        </div>

        <div v-if="details.output" class="fsnip_error_extra">
            <strong>{{ $t('What the snippet printed') }}</strong>
            <pre>{{ details.output }}</pre>
        </div>

    </div>
</template>

<script type="text/babel">
/**
 * Renders the `error_details` block that every save failure now carries.
 *
 * The editor used to show the error message and then a <pre> containing the raw error
 * data array, which rendered as things like {"line":12} — technically the explanation,
 * practically noise. The fields here (what PHP said / why / what to do) are the ones a
 * user can act on.
 *
 * PHP's own wording used to sit behind a "Show technical details" toggle, and the
 * explanation quoted it mid-paragraph so the panel would still make sense with the
 * toggle closed. That got the priority backwards: "Call to undefined function dddddd()"
 * is the line that tells you which name to go and look at, and it was both buried in
 * prose and hidden behind a click. It is now the first thing under the headline, and
 * the prose no longer repeats it.
 */
export default {
    name: 'SaveErrorPanel',
    props: {
        details: {
            type: Object,
            required: true
        }
    },
    computed: {
        showRaw() {
            // Skipped when the engine text is what the title already says, which is the
            // case for every error built entirely by the plugin.
            return !!this.details.raw && this.details.raw !== this.details.title;
        }
    }
}
</script>
