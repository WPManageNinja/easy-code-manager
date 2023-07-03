<template>
    <el-pagination class="fcart-pagination"
                   :background="false"
                   layout="total, sizes, prev, pager, next"
                   @current-change="changePage"
                   @size-change="changeSize"
                   :hide-on-single-page="hide_on_single"
                   :current-page.sync="pagination.current_page"
                   :page-sizes="page_sizes"
                   :page-size="pagination.per_page"
                   :total="pagination.total"
    />
</template>

<script type="text/babel">
export default {
    name: 'Pagination',
    props: {
        pagination: {
            required: true,
            type: Object
        },
        extra_sizes: {
            required: false,
            type: Array,
            default() {
                return [];
            }
        },
        hide_on_single: {
            required: false,
            type: Boolean,
            default() {
                return true;
            }
        }
    },
    computed: {
        page_sizes() {
            const sizes = [];
            if (this.pagination.per_page < 10) {
                sizes.push(this.pagination.per_page);
            }

            const defaults = [
                10,
                20,
                50,
                80,
                100,
                120,
                150
            ];

            return [...sizes, ...defaults, ...this.extra_sizes];
        }
    },
    methods: {
        changePage(page) {
            this.pagination.current_page = page;

            this.$emit('fetch');
        },
        changeSize(size) {
            this.pagination.per_page = size;
            this.$emit('per_page_change', size)
            this.$emit('fetch');
        }
    }
}
</script>
