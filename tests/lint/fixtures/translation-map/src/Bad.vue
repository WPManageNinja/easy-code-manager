<template>
    <!--
      Proof-of-catch fixture for tests/lint/translation-map.js. Each block is one
      defect the lint must keep detecting; the good calls at the end prove the
      positive paths (comment, reserved word, plural, context) still land in the map.
    -->
    <div>
        <!-- 2. reserved-looking key with no entry in the reserved words file -->
        <p>{{ $t('Missing_Reserved_Key') }}</p>

        <!-- 5. HTML entity: Vue decodes it at build time, the map would not -->
        <p>{{ $t('Type &quot;DELETE&quot; to confirm') }}</p>

        <!-- 7. placeholder with no translators: comment anywhere -->
        <p>{{ $t('%d items found', count) }}</p>

        <!-- 8. em dash in UI copy -->
        <p>{{ $t('Saved — nothing else to do') }}</p>

        <!-- 9. trailing space: a concatenation fragment -->
        <span>{{ $t('Wait until ') }}{{ date }}</span>

        <!-- good: translator comment on the line above, carried into the map -->
        <!-- translators: %s is the item count -->
        <p>{{ $t('%s items', total) }}</p>

        <!-- good: reserved word, expanded to its long text -->
        <p>{{ $t('Long_Help_Text') }}</p>

        <!-- good: plural pair, both forms become keys -->
        <!-- translators: %s is the number of tickets -->
        <p>{{ $_n('%s ticket', '%s tickets', total) }}</p>

        <!-- good: contextual key -->
        <span>{{ $_x('Open', 'Ticket status') }}</span>

        <!-- good: a call written inside a comment is documentation, not a key:
             $t('Example in a comment') -->
    </div>
</template>

<script>
export default {
    computed: {
        // 3. template literal: the runtime string never matches the frozen key
        label() {
            return this.$t(`Showing ${this.count} items`);
        },
        // 4. dynamic context: the contextual key cannot be built
        status() {
            return this.$_x('Active', this.contextName);
        }
    }
};
</script>
