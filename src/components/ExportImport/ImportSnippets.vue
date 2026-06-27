<template>
    <div v-if="!isImported">
        <h3>{{$t('Please upload Code Snippets JSON file to import')}}</h3>
        <el-upload
            drag
            :limit="1"
            :action="import_url"
            ref="uploader"
            :multiple="false"
            :on-error="handleUploadError"
            :on-success="handleUploadSuccess">
            <el-icon style="font-size: 30px;">
                <UploadFilled/>
            </el-icon>
            <div class="el-upload__text">
                {{ $t('Drop JSON file here or') }} <em>{{ $t('click to upload') }}</em>
            </div>
        </el-upload>
        <p>{{$t('Please upload Snippets only trusted sources.')}}</p>
    </div>
    <div v-else>
        <h3>{{$t('Imported Snippets')}} ({{ snippets.length }})</h3>
        <p>{{$t('The snippets are being saved as Draft. Please review and publish.')}}</p>

        <el-table stripe :data="snippets">
            <el-table-column min-width="150px" :label="$t('Title')">
                <template #default="scope">
                    <span>{{ scope.row.name }}</span>
                </template>
            </el-table-column>
            <el-table-column min-width="200px" :label="$t('Status')">
                <template #default="scope">
                    <span>{{ scope.row.reason }}</span>
                </template>
            </el-table-column>
            <el-table-column width="120px;" :label="$t('Action')">
                <template #default="scope">
                    <el-button :disabled="publishing" :loading="publishing"
                               v-if="scope.row.status == 'draft'" type="primary"
                               @click="updateSnippetStatus(scope.row)">
                        {{$t('Publish')}}
                    </el-button>
                    <el-tag v-else-if="scope.row.status == 'published'" style="margin-left: 10px;" size="small"
                            :type="(scope.row.status == 'published') ? 'success' : 'warning'">
                        <span v-html="scope.row.status"></span>
                    </el-tag>
                    <el-tag v-else size="small" type="error">{{$t('skipped')}}</el-tag>
                </template>
            </el-table-column>
        </el-table>

        <el-button :disabled="publishing" :loading="publishing" style="margin-top: 20px;" @click="publishAll()"
                   type="success" v-if="hasDraft && !allDone">
            {{ $t('Publish All Imported Snippets') }}
        </el-button>
    </div>
</template>

<script type="text/javascript">
import {UploadFilled} from "@element-plus/icons-vue";

export default {
    name: 'ImportSnippets',
    components: {
        UploadFilled
    },
    data() {
        return {
            snipptets: [],
            isImported: false,
            publishing: false,
            allDone: false
        }
    },
    computed: {
        import_url() {
            let url = window.ajaxurl;
            url += (url.match(/\?/) ? '&' : '?') + jQuery.param({action: 'fluent_snippets_import_json', _nonce: window.fluentSnippetAdmin.nonce});
            return url;
        },
        hasDraft() {
            return this.snippets.filter(snippet => snippet.status == 'draft').length > 0;
        }
    },
    methods: {
        handleUploadSuccess(response) {
            this.isImported = true;
            this.snippets = response.snippets;
        },
        handleUploadError(error) {
            this.$notify.error(error.message);
        },
        publishAll() {
            // publish snippet one by one
            let total = this.snippets.length;
            if(!total) {
                return;
            }

            let index = 0;
            let publishNext = () => {
                if (index < total) {
                    this.updateSnippetStatus(this.snippets[index], publishNext);
                    index++;
                } else {
                    this.publishing = false;
                    this.allDone = true;
                    this.$notify.success(this.$t('All snippets have been published successfully.'));
                }
            };
            publishNext();
        },
        updateSnippetStatus(snippet, callback = null) {
            if (!snippet.file_name) {
                callback && callback();
                return;
            }
            this.publishing = true;
            this.$post('snippets/update_status', {
                fluent_saving_snippet_name: snippet.file_name,
                status: 'published'
            })
                .then(response => {
                    snippet.reason = 'published';
                    snippet.status = 'published';
                })
                .catch((errors) => {
                    this.$handleError(errors);
                    snippet.reason = 'failed';
                    snippet.status = '';
                })
                .finally(() => {
                    this.publishing = false;
                    if (callback) {
                        callback();
                    }
                });
        }
    }
}
</script>
