<template>
    <div class="box_wrapper">
        <div class="box dashboard_box">
            <div class="box_header" style="padding: 15px;font-size: 16px;">
                <div style="padding-top: 5px;" class="box_head">
                    {{ $t('Code Snippets') }}
                    <el-button @click="getSnippets()" size="small">{{ $t('refresh') }}</el-button>
                </div>
                <div style="display: flex;" class="box_actions">
                    <el-input clearable @keyup.native.enter="getSnippets()"
                              style="width: 200px; margin-left: 10px;"
                              size="small" type="text" v-model="search" placeholder="Search">
                        <template #append>
                            <el-button @click="getSnippets()" :icon="SearchIcon"/>
                        </template>
                    </el-input>
                    <el-button style="margin-left: 10px;" @click="createSnippet()" type="primary">{{
                            $t('New Snippet')
                        }}
                    </el-button>
                </div>
            </div>
            <div v-if="loadingFirst" class="box_body">
                <el-skeleton :rows="10" animated animation="wave"/>
            </div>
            <div v-else style="padding: 15px 0;" class="box_body">
                <div class="fsnip_secondary_menu">
                    <ul class="fsnip_menu">
                        <li v-for="item in snippetMenus" :ley="item.value"
                            :class="{active_item : item.value == selectedLang}">
                            <a @click.prevent="changeLang(item.value)" href="#" v-html="item.label"></a>
                        </li>
                    </ul>
                    <div class="snip_right_items">
                        <el-radio-group @change="$storeLocalData('view_type', viewType)" v-model="viewType" size="small">
                            <el-radio-button label="table">{{ $t('Table') }}</el-radio-button>
                            <el-radio-button label="grouped">{{ $t('Grouped') }}</el-radio-button>
                        </el-radio-group>
                        <el-select class="snip_ac_item" @change="getSnippets()" clearable placeholder="All tags"
                                   filterable v-model="selectedTag">
                            <el-option v-for="tag in tags" :key="tag" :label="tag" :value="tag"></el-option>
                        </el-select>
                    </div>
                </div>

                <el-table
                    v-if="viewType == 'table'"
                    v-loading="loading"
                    :data="snippets"
                    :row-class-name="tableRowClassName"
                    style="width: 100%"
                >
                    <el-table-column width="80">
                        <template #default="scope">
                            <el-switch v-model="scope.row.status" active-value="published" inactive-value="draft"
                                       active-color="#13ce66" @change="updateSnippetStatus(scope.row)"></el-switch>
                        </template>
                    </el-table-column>

                    <el-table-column min-width="200px" :label="$t('Title')">
                        <template #default="scope">
                            <div class="snippet_name">
                                <router-link class="edit_snippet_link"
                                             :to="{ name: 'edit_snippet', params: { snippet_name: scope.row.file_name } }">
                                    <span>{{ scope.row.name }}</span>
                                </router-link>
                                <el-tag style="margin-left: 10px;" size="small"
                                        :type="(scope.row.status == 'published') ? 'success' : 'danger'">
                                    {{ scope.row.status }}
                                </el-tag>
                            </div>
                            <div class="snippet_actions">
                                <router-link class="edit_snippet_link"
                                             :to="{ name: 'edit_snippet', params: { snippet_name: scope.row.file_name } }">
                                    edit
                                </router-link>
                                <span class="fc_middot">|</span>
                                <el-popconfirm width="220" @confirm="confirmDeleteSnippet(scope.row)"
                                               title="Are you sure to delete this?">
                                    <template #reference>
                                        <span class="fsnip_delete">delete</span>
                                    </template>
                                </el-popconfirm>
                                <template v-if="scope.row.group">
                                    <span class="fc_middot">|</span>
                                    <span><el-icon><FolderOpened/></el-icon> {{ scope.row.group }}</span>
                                </template>
                            </div>
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Description')" min-width="200">
                        <template #default="scope">
                            {{ limitChars(scope.row.description, 100) }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Type')" width="120">
                        <template #default="scope">
                            <span class="fsn_label" :class="'fsn_'+scope.row.type.toLowerCase()">
                                {{ getLangLabelName(scope.row.type) }}
                            </span>
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Tags')" width="200">
                        <template #default="scope">
                            {{ scope.row.tags }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Updated At')" width="180">
                        <template #default="scope">
                            {{ relativeTimeFromUtc(scope.row.updated_at) }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="$t('Priority')" width="80">
                        <template #default="scope">
                            {{ scope.row.priority }}
                        </template>
                    </el-table-column>
                </el-table>

                <div v-else-if="groupedSnippets" v-loading="loading" class="groups_snippets">
                    <div v-for="(group, groupName) in groupedSnippets.groups" :key="groupName" class="fsnip_group">
                        <div class="group_name">
                            <el-icon>
                                <FolderOpened/>
                            </el-icon>
                            <span>{{ group.label }}</span>
                        </div>
                        <ul class="group_files">
                            <li v-for="snippet in group.snippets" :class="'fsnip_status_'+snippet.status" :key="snippet.file_name" class="group_file">
                                <div @click="$router.push({ name: 'edit_snippet', params: { snippet_name: snippet.file_name } })" class="group_file_name">
                                    <el-icon>
                                        <Document/>
                                    </el-icon>
                                    {{ snippet.name }}
                                    <span class="fsn_label" :class="'fsn_'+snippet.type.toLowerCase()">
                                        {{ getLangLabelName(snippet.type) }}
                                    </span>
                                </div>
                                <div class="group_file_meta">
                                    <div class="snippet_actions">
                                        <span title="Updated At: "><el-icon><Stopwatch /></el-icon> {{ relativeTimeFromUtc(snippet.updated_at) }}</span>
                                        <span class="fc_middot">|</span>
                                        <span @click.prevent="console.log('OK')">
                                            <el-switch size="small" v-model="snippet.status" active-value="published" inactive-value="draft"
                                                   active-color="#13ce66" @change="updateSnippetStatus(snippet)"></el-switch> {{snippet.status}}
                                        </span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <ul v-if="groupedSnippets.roots.length" class="group_files roots_files">
                        <li v-for="snippet in groupedSnippets.roots" :class="'fsnip_status_'+snippet.status" :key="snippet.file_name" class="group_file">
                            <div @click="$router.push({ name: 'edit_snippet', params: { snippet_name: snippet.file_name } })" class="group_file_name">
                                <el-icon>
                                    <Document/>
                                </el-icon>
                                {{ snippet.name }}
                                <span class="fsn_label" :class="'fsn_'+snippet.type.toLowerCase()">
                                        {{ getLangLabelName(snippet.type) }}
                                    </span>
                            </div>
                            <div class="group_file_meta">
                                <div class="snippet_actions">
                                    <span style="margin-right: 10px;">{{ limitChars(snippet.description, 50)}}</span>
                                    <span title="Updated At: "><el-icon><Stopwatch /></el-icon> {{ relativeTimeFromUtc(snippet.updated_at) }}</span>
                                    <span class="fc_middot">|</span>
                                    <span @click.prevent="console.log('OK')">
                                            <el-switch size="small" v-model="snippet.status" active-value="published" inactive-value="draft"
                                                       active-color="#13ce66" @change="updateSnippetStatus(snippet)"></el-switch> {{snippet.status}}
                                        </span>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <el-row style="margin-top: 20px; padding: 0 15px;" :gutter="30">
                    <el-col :md="12" :xs="24">

                    </el-col>
                    <el-col :md="12" :xs="24">
                        <div class="fql_pagi text-align-right" style="float: right;">
                            <el-pagination @current-change="changePage"
                                           :hide-on-single-page="true"
                                           :current-page="paginate.page"
                                           :page-size="paginate.per_page"
                                           background layout="total, prev, pager, next"
                                           :total="paginate.total"
                            />
                        </div>
                    </el-col>
                </el-row>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import {Search, FolderOpened, Document, Stopwatch} from '@element-plus/icons-vue';
import {markRaw} from 'vue';
import each from 'lodash/each';

export default {
    name: 'Dashboard',
    data() {
        return {
            snippets: [],
            SearchIcon: markRaw(Search),
            paginate: {
                page: 1,
                per_page: 100,
                total: 0
            },
            search: '',
            loading: false,
            selectedLang: 'all',
            snippetMenus: [
                {
                    label: 'All Snippets',
                    value: 'all'
                },
                {
                    label: 'Functions <span class="fsn_label fsn_php">PHP</span>',
                    value: 'PHP'
                },
                {
                    label: 'Content <span class="fsn_label fsn_mixed">PHP + HTML</span>',
                    value: 'php_content'
                },
                {
                    label: 'Styles <span class="fsn_label fsn_css">CSS</span>',
                    value: 'css'
                },
                {
                    label: 'Scripts <span class="fsn_label fsn_js">JS</span>',
                    value: 'js'
                }
            ],
            loadingFirst: true,
            tags: [],
            selectedTag: '',
            viewType: 'table'
        }
    },
    components: {
        FolderOpened,
        Document,
        Stopwatch
    },
    methods: {
        changePage(page) {
            this.paginate.page = page;
            this.getSnippets();
        },
        changeLang(lang) {
            if (this.selectedLang == lang) {
                return;
            }
            this.selectedLang = lang;
            this.paginate.page = 1;
            this.getSnippets();
        },
        getSnippets() {
            this.loading = true;
            this.$get('snippets', {
                per_page: this.paginate.per_page,
                page: this.paginate.page,
                search: this.search,
                type: this.selectedLang,
                tag: this.selectedTag
            })
                .then(response => {
                    this.snippets = response.snippets.data;
                    this.paginate.total = response.snippets.total;
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.loading = false;
                    this.loadingFirst = false;
                });
        },
        tableRowClassName({row, rowIndex}) {
            return 'fsnip_status_' + row.status;
        },
        limitChars(string, limit = 100) {
            if (!string) {
                return '--';
            }
            if (string.length > limit) {
                return string.substring(0, limit) + '...';
            }
            return string;
        },
        createSnippet() {
            this.$router.push({name: 'create_snippet'});
        },
        updateSnippetStatus(snippet) {
            this.$post('snippets/update_status', {
                fluent_saving_snippet_name: snippet.file_name,
                status: snippet.status
            })
                .then(response => {
                    this.$notify.success(response.message);
                })
                .catch((errors) => {
                    this.$handleError(errors);
                    this.getSnippets();
                });
        },
        confirmDeleteSnippet(snippet) {
            this.$post('snippets/delete_snippet', {
                fluent_saving_snippet_name: snippet.file_name
            })
                .then(response => {
                    this.$notify.success(response.message);
                })
                .catch((errors) => {
                    this.$handleError(errors);
                })
                .finally(() => {
                    this.getSnippets();
                })
        }
    },
    computed: {
        groupedSnippets() {
            if (this.viewType == 'table') {
                return null;
            }
            const groups = {};

            const roots = [];

            each(this.snippets, (snippet) => {
                let group = snippet.group;
                if (!group) {
                    roots.push(snippet);
                } else {
                    if (!groups[group]) {
                        groups[group] = {
                            label: group,
                            snippets: []
                        };
                    }
                    groups[group].snippets.push(snippet);
                }
            });

            // values from groups
            const groupArray = Object.values(groups);

            // sort each group snippets by name
            groupArray.forEach((group) => {
                group.snippets.sort((a, b) => {
                    return a.name.localeCompare(b.name);
                });
            });

            // Short the groups by label
            groupArray.sort((a, b) => {
                return a.label.localeCompare(b.label);
            });

            // short the roots with name
            roots.sort((a, b) => {
                return a.name.localeCompare(b.name);
            });

            return {
                groups: groupArray,
                roots: roots
            };
        }
    },
    created() {
        this.viewType = this.$getLocalData('view_type', 'table');
        this.getSnippets();
        this.tags = this.appVars.tags;
    }
}
</script>
