<template>
    <div class="ecm_import_export">
        <div v-if="!currentView" class="ecm_choices">
            <button type="button" class="ecm_choice" @click="currentView = 'export'">
                <el-icon><Download /></el-icon>
                <span>{{$t('Export Snippets')}}</span>
            </button>
            <button v-if="canEdit" type="button" class="ecm_choice" @click="currentView = 'import'">
                <el-icon><UploadFilled /></el-icon>
                <span>{{$t('Import Snippets')}}</span>
            </button>
        </div>
        <div v-else-if="currentView == 'import'">
            <ImportSnippets />
        </div>
        <div v-else-if="currentView == 'export'">
            <ExportSnippets :snippets="snippets"/>
        </div>
    </div>
</template>

<script type="text/babel">
import ImportSnippets from "./ImportSnippets.vue";
import ExportSnippets from "./ExportSnippets.vue";
import {Download, UploadFilled} from "@element-plus/icons-vue";

export default {
    name: 'ImportExportChoice',
    props: ['snippets'],
    components: {
        ImportSnippets,
        ExportSnippets,
        Download,
        UploadFilled
    },
    data() {
        return {
            currentView: ''
        }
    }
}
</script>
