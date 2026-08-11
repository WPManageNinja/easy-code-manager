<template>
    <div class="box_wrapper">
        <div class="box">
            <div class="box_header">
                <div class="box_head">
                    {{ $t('Code Snippets') }}
                </div>
                <div class="box_actions">
                    <el-switch activeValue="yes" inactive-value="no" @change="toggleHideInactive" v-model="hideInactive" :active-text="$t('Hide Inactives')" />

                    <el-input clearable class="fsnip_search"
                              size="small" type="text" v-model="search" :placeholder="$t('Search')">
                        <template #append>
                            <el-button :icon="SearchIcon"/>
                        </template>
                    </el-input>
                    <el-button v-if="canEdit" @click="createSnippet()" type="primary">{{
                            $t('New Snippet')
                        }}
                    </el-button>
                    <el-button @click="showImportExport = true;">{{$t('Export/Import')}}</el-button>
                </div>
            </div>
            <div v-if="loadingFirst" class="box_body">
                <el-skeleton :rows="10" animated animation="wave"/>
            </div>
            <div v-else-if="is_empty && !loading" class="box_body">
                <div class="fsnip_empty">
                    <h1>{{$t('Thanks for installing FluentSnippets')}}</h1>
                    <p>{{$t('The High-Performance Code Snippets Plugin for WordPress')}}</p>
                    <el-button v-if="canEdit" @click="createSnippet()" size="large" type="primary">
                        {{ $t('Create Your First Snippet') }}
                    </el-button>
                    <!--
                        Nothing here yet and no way to add one from this screen. The banner
                        at the top of the page says why; this says what to do instead.
                    -->
                    <p v-else>
                        {{ $t('There are no snippets on this site yet, and this screen cannot add one. Put a snippet file into wp-content/fluent-snippets and it will appear here.') }}
                    </p>
                </div>
            </div>
            <div v-else class="box_body">
                <div class="fsnip_secondary_menu">
                    <ul class="fsnip_menu">
                        <li :class="{active_item : 'all' == selectedLang}">
                            <a @click.prevent="changeLang('all')" href="#">{{ $t('All Snippets') }}</a>
                        </li>
                        <li v-for="(item, itemKey) in appVars.snippet_types" :key="itemKey"
                            :class="{active_item : itemKey == selectedLang}">
                            <a @click.prevent="changeLang(itemKey)" href="#">
                                {{ item.label }} <span class="fsn_label" :class="'fsn_'+itemKey">
                                {{ item.inline_tag }}
                            </span>
                            </a>
                        </li>
                    </ul>
                    <div class="snip_right_items">
                        <el-radio-group @change="$storeLocalData('view_type', viewType)" v-model="viewType">
                            <el-radio-button value="grouped">{{ $t('Grouped') }}</el-radio-button>
                            <el-radio-button value="table">{{ $t('Table') }}</el-radio-button>
                        </el-radio-group>
                        <el-select class="snip_ac_item"
                                   clearable :placeholder="$t('All tags')"
                                   filterable v-model="selectedTag">
                            <el-option v-for="tag in tags" :key="tag" :label="tag" :value="tag"></el-option>
                        </el-select>
                        <!--
                            v-model:visible, not :visible. Bound one way the popover is
                            fully controlled, so Element Plus will not close it on an
                            outside click or a second press of the trigger - the only
                            thing that ever set it back to false was Apply, which left
                            the panel stuck open every other way out of it.
                        -->
                        <el-popover v-model:visible="showingPop" trigger="click"
                                    placement="bottom-end" width="auto">
                            <div class="fct_filter_items">
                                <h3>{{$t('Sort By')}}</h3>
                                <div class="fct_sort_options">
                                    <el-radio-group class="fct_radios_blocks" v-model="sorting.sortBy">
                                        <el-radio v-for="column in sortingOrderColumns" :key="column.value"
                                                  :value="column.value">
                                            {{ column.label }}
                                        </el-radio>
                                    </el-radio-group>
                                </div>
                                <hr/>
                                <el-radio-group size="small" v-model="sorting.sortType">
                                    <el-radio-button value="ASC">{{ $t('Ascending') }}</el-radio-button>
                                    <el-radio-button value="DESC">{{ $t('Descending') }}</el-radio-button>
                                </el-radio-group>
                                <el-button class="fct_sort_apply" @click="applySorting()" type="primary">{{ $t('Apply') }}</el-button>
                            </div>
                            <template #reference>
                                <el-button type="default">
                                    <el-icon class="fsnip_btn_icon">
                                        <SortIcon/>
                                    </el-icon>
                                    {{ $t('Sort') }}
                                </el-button>
                            </template>
                        </el-popover>
                    </div>
                </div>

                <div v-if="!loading">
                    <!--
                        Every column here is one fact about the snippet, and every row is
                        the same two lines tall. The previous table put five actions inline
                        under each title, and that strip wrapped to a second or third line
                        depending on how long the group name was, so no two rows were the
                        same height. The actions are now an icon column that appears on
                        hover, and the two facts that were buried in that strip - the group
                        and where the snippet runs - are a line under the title and a
                        column of their own.
                    -->
                    <div v-if="viewType == 'table' && snippets.length" class="fsnip_table_wrap">
                    <el-table
                        v-loading="loading"
                        :data="snippets"
                        :row-class-name="tableRowClassName"
                        class="fsnip_table"
                    >
                        <!--
                            80, not 64. This is the first column, so it carries the 20px
                            left inset the card edge needs on top of Element Plus's own
                            12px on the right - 32px of padding around a 40px switch. At
                            64 (and at 70) the switch was wider than the space left for
                            it, and an overflowing cell renders the ellipsis its
                            text-overflow asks for: a "..." floating beside every toggle.
                        -->
                        <el-table-column width="80" class-name="fsnip_col_status">
                            <template #default="scope">
                                <!-- A snippet that fataled has no toggle; the title says Paused. -->
                                <el-switch v-if="!scope.row.error" v-model="scope.row.status" active-value="published"
                                           inactive-value="draft" :disabled="!canEdit"
                                           @change="updateSnippetStatus(scope.row)"></el-switch>
                            </template>
                        </el-table-column>

                        <el-table-column min-width="270" :label="$t('Title')">
                            <template #default="scope">
                                <div class="fsnip_row_head">
                                    <!-- The title attribute is what a truncated name is read with. -->
                                    <router-link class="fsnip_row_title" :title="scope.row.name"
                                                 :to="{ name: 'edit_snippet', params: { snippet_name: scope.row.file_name } }">
                                        {{ scope.row.name }}
                                    </router-link>
                                    <!--
                                        The toggle in the column to the left is a control,
                                        not a label - it says what pressing it will do, not
                                        what the snippet is. The word stays.
                                    -->
                                    <span v-if="scope.row.error" class="fsnip_tag is_error">{{ $t('Paused') }}</span>
                                    <span v-else class="fsnip_tag"
                                          :class="(scope.row.status == 'published') ? 'is_success' : 'is_warning'">
                                        {{ scope.row.status }}
                                    </span>
                                </div>
                                <!--
                                    The line under the title: which group the snippet is
                                    filed in, then what you can do to it. The actions are
                                    always on rather than revealed on hover - there is room
                                    for them here, and an action you can see is one you do
                                    not have to discover.

                                    Always rendered, even for an ungrouped snippet: the row
                                    is two lines tall and an empty line still has to occupy
                                    one of them.
                                -->
                                <div class="fsnip_row_meta">
                                    <span v-if="scope.row.group" class="fsnip_row_group">
                                        <el-icon><FolderOpened/></el-icon><span>{{ scope.row.group }}</span>
                                    </span>

                                    <div class="fsnip_row_actions">
                                        <router-link class="fsnip_row_action" :title="$t('Edit')" :aria-label="$t('Edit')"
                                                     :to="{ name: 'edit_snippet', params: { snippet_name: scope.row.file_name } }">
                                            <el-icon><EditPen/></el-icon>
                                        </router-link>
                                        <button type="button" class="fsnip_row_action" :title="$t('Download')"
                                                :aria-label="$t('Download')" @click="exportSnippets([scope.row.file_name])">
                                            <el-icon><Download/></el-icon>
                                        </button>
                                        <el-popconfirm v-if="canEdit" width="220" @confirm="confirmDeleteSnippet(scope.row)"
                                                       :title="$t('Are you sure to delete this?')">
                                            <template #reference>
                                                <button type="button" class="fsnip_row_action is_danger"
                                                        :title="$t('Delete')" :aria-label="$t('Delete')">
                                                    <el-icon><Delete/></el-icon>
                                                </button>
                                            </template>
                                        </el-popconfirm>
                                    </div>
                                </div>
                            </template>
                        </el-table-column>

                        <el-table-column :label="$t('Description')" min-width="175">
                            <template #default="scope">
                                <span v-if="scope.row.error" class="fsnip_row_desc text-danger-fg">
                                    {{ scope.row.error }}
                                </span>
                                <span v-else-if="scope.row.description" class="fsnip_row_desc">
                                    {{ scope.row.description }}
                                </span>
                                <span v-else class="fsnip_row_blank">&mdash;</span>
                            </template>
                        </el-table-column>

                        <el-table-column :label="$t('Type')" width="96">
                            <template #default="scope">
                                <span v-if="scope.row.type" class="fsn_label" :class="'fsn_'+scope.row.type.toLowerCase()">
                                    {{ getLangLabelName(scope.row.type) }}
                                </span>
                            </template>
                        </el-table-column>

                        <!--
                            Priority sits in this column rather than under the title
                            because it is not a property of the snippet, it is the order it
                            runs in against everything else on the same hook - so it only
                            means anything next to the hook. Inline beside it, the way the
                            status sits beside the title.

                            A glyph and the number, not the word: "Priority" spelled out on
                            every row is one column of the same six letters repeated, and
                            the number is the only part that varies. The word is still
                            there for anyone who needs it, on the tooltip and for screen
                            readers.
                        -->
                        <el-table-column :label="$t('Runs At')" width="200">
                            <template #default="scope">
                                <div class="fsnip_row_hook">
                                    <span class="fsnip_row_runs">
                                        <el-icon>
                                            <svg viewBox="0 0 8 8" fill="currentColor" xmlns="http://www.w3.org/2000/svg"><path
                                                d="M3 0l-3 5h2v3l3-5h-2v-3z" transform="translate(1)"></path></svg>
                                        </el-icon>
                                        {{ getRunAtName(scope.row.run_at) }}
                                    </span>
                                    <span class="fsnip_row_priority"
                                          :title="$t('Priority %s', scope.row.priority)"
                                          :aria-label="$t('Priority %s', scope.row.priority)">
                                        <el-icon>
                                            <!-- Descending bars: a queue, shortest last. -->
                                            <svg viewBox="0 0 12 12" fill="currentColor" xmlns="http://www.w3.org/2000/svg">
                                                <rect x="0" y="1" width="12" height="1.7" rx=".85"/>
                                                <rect x="0" y="5.15" width="8" height="1.7" rx=".85"/>
                                                <rect x="0" y="9.3" width="4" height="1.7" rx=".85"/>
                                            </svg>
                                        </el-icon>{{ scope.row.priority }}
                                    </span>
                                </div>
                            </template>
                        </el-table-column>

                        <el-table-column :label="$t('Tags')" width="150">
                            <template #default="scope">
                                <div v-if="tagList(scope.row).length" class="fsnip_row_tags">
                                    <span v-for="tag in tagList(scope.row)" :key="tag" class="fsnip_chip">{{ tag }}</span>
                                </div>
                                <span v-else class="fsnip_row_blank">&mdash;</span>
                            </template>
                        </el-table-column>

                        <el-table-column :label="$t('Updated')" width="108">
                            <template #default="scope">
                                <span class="fsnip_row_when">{{ relativeTimeFromUtc(scope.row.updated_at) }}</span>
                            </template>
                        </el-table-column>
                    </el-table>
                    </div>
                    <div v-else-if="snippets.length && groupedSnippets" v-loading="loading" class="groups_snippets">
                        <div v-for="(group, groupName) in groupedSnippets.groups" :key="groupName" class="fsnip_group">
                            <div class="group_name">
                                <el-icon @click="toggleGroupView(groupName)">
                                    <FolderOpened v-if="!groupCollapsed[groupName]"/>
                                    <FolderClosed v-else/>
                                </el-icon>
                                <span @click="toggleGroupView(groupName)">{{ group.label }}</span>
                            </div>
                            <ul v-if="!groupCollapsed[groupName]" class="group_files">
                                <li v-for="snippet in group.snippets" :class="'fsnip_status_'+snippet.status"
                                    :key="snippet.file_name" class="group_file">
                                    <div
                                        @click="$router.push({ name: 'edit_snippet', params: { snippet_name: snippet.file_name } })"
                                        class="group_file_name">
                                        <el-icon>
                                            <Document/>
                                        </el-icon>
                                        {{ snippet.name }}
                                        <template v-if="snippet.error">
                                            <span class="fsn_label fsn_error">{{$t('Error:')}}</span>
                                            <span class="text-danger-fg">
                                            {{ limitChars(snippet.error, 100) }}
                                        </span>
                                        </template>
                                        <span v-else class="fsn_label" :class="'fsn_'+snippet.type.toLowerCase()">
                                            {{ getLangLabelName(snippet.type) }}
                                    </span>
                                        <span class="fsn_label">
                                        <el-icon>
                                                <svg viewBox="0 0 8 8" fill="currentColor"
                                                     xmlns="http://www.w3.org/2000/svg"><path
                                                    d="M3 0l-3 5h2v3l3-5h-2v-3z" transform="translate(1)"></path></svg>
                                        </el-icon>
                                        {{ getRunAtName(snippet.run_at) }}
                                    </span>
                                    </div>
                                    <div class="group_file_meta">
                                        <div class="snippet_actions">
                                        <span :title="$t('Updated At:') + ' '"><el-icon><Stopwatch/></el-icon> {{
                                                relativeTimeFromUtc(snippet.updated_at)
                                            }}</span>
                                            <span class="fc_middot">|</span>
                                            <el-popconfirm v-if="canEdit" width="220" @confirm="confirmDeleteSnippet(snippet)"
                                                           :title="$t('Are you sure to delete this?')">
                                                <template #reference>
                                                    <span class="fsnip_delete">{{ $t('delete') }}</span>
                                                </template>
                                            </el-popconfirm>
                                            <span class="fc_middot">|</span>
                                            <span class="fsnip_download" @click="exportSnippets([snippet.file_name])">
                                            <el-icon>
                                                <download />
                                            </el-icon>
                                            {{ $t('Download') }}
                                        </span>

                                            <span class="fc_middot">|</span>
                                            <span v-if="!snippet.error">
                                            <el-switch size="small" :disabled="!canEdit" v-model="snippet.status" active-value="published"
                                                       inactive-value="draft"
                                                       @change="updateSnippetStatus(snippet)"></el-switch>
                                            {{ snippet.status }}
                                        </span>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                        </div>
                        <ul v-if="groupedSnippets.roots.length" class="group_files roots_files">
                            <li v-for="snippet in groupedSnippets.roots" :class="'fsnip_status_'+snippet.status"
                                :key="snippet.file_name" class="group_file">
                                <div
                                    @click="$router.push({ name: 'edit_snippet', params: { snippet_name: snippet.file_name } })"
                                    class="group_file_name">
                                    <el-icon>
                                        <Document/>
                                    </el-icon>
                                    {{ snippet.name }}
                                    <template v-if="snippet.error">
                                    <span class="fsn_label fsn_error">{{ $t('Error:') }}</span>
                                        <span class="text-danger-fg">{{
                                                limitChars(snippet.error, 100)
                                            }}</span>
                                    </template>
                                    <span v-else class="fsn_label" :class="'fsn_'+snippet.type.toLowerCase()">
                                        {{ getLangLabelName(snippet.type) }}
                                </span>
                                    <span class="fsn_label">
                                    <el-icon>
                                            <svg viewBox="0 0 8 8" fill="currentColor"
                                                 xmlns="http://www.w3.org/2000/svg"><path
                                                d="M3 0l-3 5h2v3l3-5h-2v-3z" transform="translate(1)"></path></svg>
                                    </el-icon>
                                    {{ getRunAtName(snippet.run_at) }}
                                </span>
                                </div>
                                <div class="group_file_meta">
                                    <div class="snippet_actions">
                                    <span v-if="!snippet.error"
                                          class="snippet_desc">{{ limitChars(snippet.description, 50) }}</span>
                                        <span :title="$t('Updated At:')"><el-icon><Stopwatch/></el-icon>
                                        {{ relativeTimeFromUtc(snippet.updated_at) }}
                                    </span>
                                        <span class="fc_middot">|</span>
                                        <el-popconfirm v-if="canEdit" width="220" @confirm="confirmDeleteSnippet(snippet)"
                                                       :title="$t('Are you sure to delete this?')">
                                            <template #reference>
                                                <span class="fsnip_delete">{{ $t('delete') }}</span>
                                            </template>
                                        </el-popconfirm>
                                        <span class="fc_middot">|</span>
                                        <span class="fsnip_download" @click="exportSnippets([snippet.file_name])">
                                        <el-icon>
                                            <download />
                                        </el-icon>
                                        {{ $t('Download') }}
                                    </span>
                                        <span class="fc_middot">|</span>
                                        <span>
                                        <el-switch v-if="!snippet.error" size="small" :disabled="!canEdit" v-model="snippet.status"
                                                   active-value="published" inactive-value="draft"
                                                   @change="updateSnippetStatus(snippet)"></el-switch>
                                        {{ snippet.status }}
                                    </span>
                                    </div>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div v-else class="fsnip_empty">
                        <p>{{$t('Sorry, no snippets found based on your filter.')}}</p>
                        <p v-if="hiddenInactiveCount">
                            {{ $t('Inactive snippets hidden by the Hide Inactives filter') }} ({{ hiddenInactiveCount }})
                        </p>
                        <div class="fsnip_empty_actions">
                            <el-button v-if="hiddenInactiveCount" @click="toggleHideInactive('no')" type="primary">
                                {{ $t('Show Inactive Snippets') }}
                            </el-button>
                            <el-button @click="resetFilters()">{{ $t('Reset Filters') }}</el-button>
                        </div>
                    </div>
                    <div class="fql_pagi">
                        <el-pagination @current-change="changePage"
                                       :hide-on-single-page="true"
                                       :current-page="paginate.page"
                                       :page-size="paginate.per_page"
                                       background layout="total, prev, pager, next"
                                       :total="paginate.total"
                        />
                    </div>
                </div>
                <div v-else class="box_body">
                    <el-skeleton :rows="10" animated animation="wave"/>
                </div>
            </div>
        </div>

        <el-drawer
            v-model="showImportExport"
            :title="$t('Import / Export Snippets')"
            direction="rtl"
            size="70%"
            :append-to-body="true"
            @closed="getSnippets"
        >
            <import-export-choice v-if="showImportExport" :snippets="snippets" />
        </el-drawer>

    </div>
</template>

<script type="text/babel">
import {Search, FolderOpened, Folder, Document, Stopwatch, Sort, Download, EditPen, Delete} from '@element-plus/icons-vue';
import {markRaw} from 'vue';
import each from 'lodash/each';
import Fuse from 'fuse.js'
import ImportExportChoice from "./ExportImport/ImportExportChoice.vue";

export default {
    name: 'Dashboard',
    data() {
        return {
            rawSnippets: [],
            SearchIcon: markRaw(Search),
            paginate: {
                page: 1,
                per_page: 200,
                total: 0
            },
            sorting: {
                sortType: 'DESC',
                sortBy: 'created_at'
            },
            search: '',
            loading: false,
            selectedLang: 'all',
            loadingFirst: true,
            tags: [],
            selectedTag: '',
            viewType: 'grouped',
            hideInactive: 'no',
            sortingOrderColumns: [
                {
                    value: 'name',
                    label: this.$t('Name')
                },
                {
                    value: 'created_at',
                    label: this.$t('Created At')
                },
                {
                    value: 'updated_at',
                    label: this.$t('Updated At')
                },
                {
                    value: 'priority',
                    label: this.$t('Priority')
                }
            ],
            showingPop: false,
            groupCollapsed: {},
            showImportExport: false,
            loadedDone: false
        }
    },
    components: {
        FolderOpened: markRaw(FolderOpened),
        Document: markRaw(Document),
        Stopwatch: markRaw(Stopwatch),
        SortIcon: markRaw(Sort),
        FolderClosed: markRaw(Folder),
        ImportExportChoice,
        Download: markRaw(Download),
        EditPen: markRaw(EditPen),
        Delete: markRaw(Delete)
    },
    methods: {
        changePage(page) {
            this.paginate.page = page;
            this.getSnippets();
        },
        toggleGroupView(groupName) {
            if (this.groupCollapsed[groupName]) {
                delete this.groupCollapsed[groupName];
            } else {
                this.groupCollapsed[groupName] = true;
            }
            this.$storeLocalData('group_collapsed', this.groupCollapsed);
        },
        applySorting() {
            this.$storeLocalData('snippet_sorting', this.sorting);
            this.getSnippets();
        },
        toggleHideInactive(value) {
            this.hideInactive = value == 'yes' ? 'yes' : 'no';
            this.$storeLocalData('hide_inactive', this.hideInactive);
        },
        resetFilters() {
            this.search = '';
            this.selectedTag = '';
            this.toggleHideInactive('no');
            this.changeLang('all');
        },
        changeLang(lang) {
            if (this.selectedLang == lang) {
                return;
            }
            this.selectedLang = lang;
            this.paginate.page = 1;
            this.getSnippets();
        },
        syncIndex(initialLoad) {
            // Heals an index that has drifted from the files on disk — an FTP edit, a
            // deploy, a migration, a deleted index.php. Fired once per app boot and
            // deliberately not awaited, so the list still renders from the existing
            // index straight away.
            this.$post('snippets/sync-index')
                .then(response => {
                    if (!response.changed) {
                        return;
                    }

                    // Wait for the first load to settle before re-fetching, otherwise its
                    // pre-rebuild response could land last and put the stale list back.
                    return Promise.resolve(initialLoad).then(() => this.getSnippets());
                })
                .catch(() => {
                    // A failed self-heal is not worth an error in the UI: the list rendered
                    // from the existing index is still perfectly usable.
                });
        },
        getSnippets() {
            this.showingPop = false;
            this.loading = true;
            return this.$get('snippets', {
                per_page: this.paginate.per_page,
                page: this.paginate.page,
                type: this.selectedLang,
                sort_by: this.sorting.sortBy,
                sort_order: this.sorting.sortType
            })
                .then(response => {
                    this.rawSnippets = response.snippets.data || [];
                    this.paginate.total = response.snippets.total || 0;

                    if (response.tags) {
                        this.tags = response.tags;
                        this.appVars.tags = response.tags;
                    }

                    if (response.groups) {
                        this.appVars.groups = response.groups;
                    }
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
            if (row.error) {
                return 'fsnip_status_error';
            }
            return 'fsnip_status_' + row.status;
        },
        /**
         * A snippet's tags, as a list.
         *
         * They are stored as one comma-separated string, which the table used to print
         * verbatim - "analytics,tracking", no space, no chip, indistinguishable from a
         * description. Split here so the cell can render one chip per tag.
         *
         * @param {Object} snippet
         * @returns {String[]}
         */
        tagList(snippet) {
            if (!snippet.tags) {
                return [];
            }

            return snippet.tags.split(',')
                .map(tag => tag.trim())
                .filter(tag => tag);
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
                    this.loading = true;
                    setTimeout(() => {
                        this.getSnippets();
                    }, 2000);
                });
        },
        getRunAtName(runAt) {
            const trans = {
                'all': this.$t('Everywhere'),
                'backend': this.$t('Admin only'),
                'frontend': this.$t('Frontend only'),
                'wp_head': this.$t('Frontend head'),
                'wp_footer': this.$t('Frontend footer'),
                'wp_body_open': this.$t('Frontend body open'),
                'before_content': this.$t('Before post content'),
                'after_content': this.$t('After post content'),
                'admin_head': this.$t('Backend Head'),
                'admin_footer': this.$t('Backend footer'),
                'everywhere': this.$t('Everywhere')
            };

            return trans[runAt] || runAt;
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
        },
        hiddenInactiveCount() {
            if (this.hideInactive != 'yes') {
                return 0;
            }
            // exact complement of the hideInactive filter on the snippets computed
            return this.rawSnippets.filter((snippet) => snippet.status !== 'published').length;
        },
        is_empty() {
            // search, tag and hideInactive are client side filters over rawSnippets,
            // so they must not be taken into account here. Otherwise the onboarding
            // screen gets skipped on a fresh install when one of them is active.
            // paginate.total covers every page, so landing on an emptied out page
            // does not get mistaken for a fresh install either.
            return !this.paginate.total && this.selectedLang == 'all';
        },
        snippets() {

            if (!this.search && !this.selectedTag && this.hideInactive != 'yes') {
                return this.rawSnippets;
            }

            let snippets = this.rawSnippets;

            if (this.selectedTag) {
                snippets = snippets.filter((snippet) => {
                    const tags = snippet.tags || '';
                    if (!tags) {
                        return false;
                    }
                    const tagsArr = tags.split(',');
                    return tagsArr.includes(this.selectedTag);
                });
            }

            if(this.hideInactive === 'yes') {
                snippets = snippets.filter((snippet) => {
                    return snippet.status === 'published';
                });
            }

            if (!this.search) {
                return snippets;
            }

            const fuse = new Fuse(snippets, {
                keys: ['name', 'description', 'tags']
            });

            snippets = fuse.search(this.search);

            return snippets.map((snippet) => {
                return snippet.item;
            });
        }
    },
    created() {
        this.viewType = this.$getLocalData('view_type', 'grouped');
        this.sorting = this.$getLocalData('snippet_sorting', {
            sortType: 'DESC',
            sortBy: 'created_at'
        });
        this.hideInactive = this.$getLocalData('hide_inactive', 'no');
        this.groupCollapsed = this.$getLocalData('group_collapsed', {});

        this.loadedDone = true;

        this.syncIndex(this.getSnippets());
        this.tags = this.appVars.tags;
    }
}
</script>
