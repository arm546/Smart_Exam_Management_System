</div>
    <footer class="sticky-footer bg-white">
        <div class="container my-auto">
            <div class="copyright text-center my-auto">
                <span>Copyright &copy; Smart Exam System <?=date("Y")?></span>
            </div>
        </div>
    </footer>

</div>
</div>
<a class="scroll-to-top rounded" href="#page-top">
    <i class="fas fa-angle-up"></i>
</a>

<script src="vendor/jquery/jquery.min.js"></script>
<script src="vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="vendor/jquery-easing/jquery.easing.min.js"></script>
<script src="js/sb-admin-2.min.js"></script>

<script src="vendor/chart.js/Chart.min.js"></script>
<script src="vendor/datatables/jquery.dataTables.min.js"></script>
<script src="vendor/datatables/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://npmcdn.com/flatpickr/dist/l10n/th.js"></script>

<script>
    $(document).ready(function() {
        // เปิดใช้งาน DataTables พื้นฐาน
        if ($('#dataTable').length) {
            $('#dataTable').DataTable({
                "order": [], // ปิดการเรียงลำดับอัตโนมัติ เพื่อให้ยึดตามที่ PHP เรียงมา
                language: {
                    url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json'
                }
            });
        }

        // เปิดใช้งาน Select2 (Searchable Dropdown) แบบคลีนๆ
        if ($('.searchable-select').length) {
            var $searchableSelect = $('.searchable-select').select2({
                width: '100%', 
                placeholder: '-- คลิกที่นี่เพื่อเลือก หรือ พิมพ์ค้นหา --', // ดึง placeholder มาไว้ตรงนี้ให้ชัวร์
                allowClear: true, // เพิ่มปุ่ม (x) เพื่อให้ผู้ใช้กดล้างค่าที่เลือกได้ง่ายๆ
                language: {
                    noResults: function() {
                        return "ไม่พบข้อมูลที่ค้นหา"; 
                    }
                }
            });

            // เพิ่ม Event เมื่อ Dropdown ถูกเปิดขึ้นมา ให้ใส่ Placeholder เข้าไปในช่องค้นหา
            $searchableSelect.on('select2:open', function (e) {
                const searchField = document.querySelector('.select2-search__field');
                if (searchField) {
                    searchField.placeholder = 'พิมพ์ชื่อวิชา หรือ รหัสวิชาที่นี่...'; // ข้อความที่จะแสดงในช่องพิมพ์
                    searchField.focus(); // ให้ cursor ไปกระพริบรอพิมพ์ทันที
                }
            });
        }
    });
</script>

</body>
</html>