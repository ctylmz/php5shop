<?php defined('SYSPATH') or die('No direct script access.');?>
                <div class="topblock2">
			Сортировать:<br>
                        <select id="sortset" size="1" style="width: 160px; margin-left: 0px;">
                            <option value="1">по дате (новые в конце)</option>
                            <option value="2">по дате (новые в начале) </option>
                            <option value="3">по названию</option>
                            <option value="4">по названию (обратно)</option>
                            <option value="5">по цене (с меньшей)</option>
                            <option value="6">по цене (с большей)</option>

                        </select>
		</div>
<script type="text/javascript">
    $("#sortset option:[value='<?php echo $type; ?>']").attr('selected', 'selected');
    $('#sortset').change(function() {   
        document.location.href = $('base').attr('href') + 'shop/sortset/' + $('#sortset').val();
        }
    );
</script>
