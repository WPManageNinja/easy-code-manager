<template>
    <div class="fsnip_error_panel">
        <div class="fsnip_error_head">
            <span class="dashicons dashicons-warning"></span>
            <div class="fsnip_error_head_text">
                <h4>{{ details.title || $t('The snippet could not be saved') }}</h4>
                <span v-if="details.line" class="fsnip_error_line">{{ $t('Line') }} {{ details.line }}</span>
            </div>
        </div>

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

        <div v-if="showRaw" class="fsnip_error_extra">
            <a href="#" @click.prevent="raw_visible = !raw_visible">
                {{ raw_visible ? $t('Hide technical details') : $t('Show technical details') }}
            </a>
            <pre v-if="raw_visible">{{ details.raw }}</pre>
        </div>
    </div>
</template>

<script type="text/babel">
/**
 * Renders the `error_details` block that every save failure now carries.
 *
 * The editor used to show the error message and then a <pre> containing the raw error
 * data array, which rendered as things like {"line":12} — technically the explanation,
 * practically noise. The three fields here (what happened / why / what to do) are the
 * ones a user can act on; the engine's own wording is tucked behind a toggle for when
 * it is being pasted into a support ticket.
 */
export default {
    name: 'SaveErrorPanel',
    props: {
        details: {
            type: Object,
            required: true
        }
    },
    data() {
        return {
            raw_visible: false
        }
    },
    computed: {
        showRaw() {
            // Hidden when the engine text is what the title already says, which is the
            // case for every error built entirely by the plugin.
            return !!this.details.raw && this.details.raw !== this.details.title;
        }
    },
    watch: {
        details() {
            this.raw_visible = false;
        }
    }
}
</script>
