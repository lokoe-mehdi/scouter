<?php
namespace Charts;

class Pie extends Chart
{

    private $data;
    private $title;
    private $subtitle;

    public function __construct($title,$data,$subtitle="")
    {
        $this->title = $title;
        $this->data = $data;
        $this->subtitle = $subtitle;
    }

    public function draw($id){
        $html = "
        <div id=\"".$id."\" style=\"min-width: 300px; height: 400px; margin: 0 auto\"></div>
        
        <script type=\"text/javascript\">
        // Build the chart
        Highcharts.chart('".$id."', {
          chart: {
            plotBackgroundColor: null,
            plotBorderWidth: null,
            plotShadow: false,
            type: 'pie'
          },
          title: {
            text: 'Browser market shares in January, 2018'
          },
          tooltip: {
            shared:true,
            pointFormat: '{series.name}: <b>{point.percentage:.1f}%</b>'
          },
          plotOptions: {
            pie: {
              allowPointSelect: true,
              cursor: 'pointer',
              dataLabels: {
                enabled: true,
                format: '{point.percentage:.2f}% ({point.y:.0f})', // one decimal
              },
              showInLegend: true
            }
          },
          series: [{
            name: 'Brands',
            colorByPoint: true,
            data: [";
            
            foreach($this->data as $val){
              $html.= "{
                name:'".$val->label."',
                y: $val->metric
              },";
            }

            $html.="]
          }]
        });
        </script>";

        $html='<canvas id="'.$id.'"></canvas><script type="text/javascript">';

        $html.="var ctx = document.getElementById('$id').getContext('2d');

        var data = {
          labels :[";
          $i=0;
          foreach($this->data as $val){
            if($i>0) { $html.=','; }
            $html.= "'".$val->label."'";
            $i++;
          }
          $html.="],
          datasets:[
            {
              label:'Codes',
              data:[";
              $i=0;
              foreach($this->data as $val){
                if($i>0) { $html.=','; }
                $html.= $val->metric;
                $i++;
              }
              $html.="],
              backgroundColor: ['#363340', '#A63C4F', '#96A65D', '#F2C5BB', '#4F6D7A', '#D9BF77', '#6A3E37', '#C2D4D8', '#E07A5F', '#7A9E7E']
            }
          ]
        }
        
        var options = {
          title:{
            display:true,
            text:'Response codes'
          },
          animation: {
            duration: 500,
            easing: \"easeOutQuart\",
            onComplete: function () {
              var ctx = this.chart.ctx;
              ctx.font = Chart.helpers.fontString(Chart.defaults.global.defaultFontFamily, 'normal', Chart.defaults.global.defaultFontFamily);
              ctx.textAlign = 'center';
              ctx.textBaseline = 'bottom';
        
              this.data.datasets.forEach(function (dataset) {
        
                for (var i = 0; i < dataset.data.length; i++) {
                  var model = dataset._meta[Object.keys(dataset._meta)[0]].data[i]._model,
                      total = dataset._meta[Object.keys(dataset._meta)[0]].total,
                      mid_radius = model.innerRadius + (model.outerRadius - model.innerRadius)/2,
                      start_angle = model.startAngle,
                      end_angle = model.endAngle,
                      mid_angle = start_angle + (end_angle - start_angle)/2;
        
                  var x = mid_radius * Math.cos(mid_angle);
                  var y = mid_radius * Math.sin(mid_angle);
        
                  ctx.fillStyle = '#fff';
                  if (i == 3){ // Darker text color for lighter background
                    ctx.fillStyle = '#444';
                  }
                  var percent = String(Math.round(dataset.data[i]/total*100)) + \"%\";      
                  //Don't Display If Legend is hide or value is 0
                  if(dataset.data[i] != 0 && dataset._meta[0].data[i].hidden != true) {
                    ctx.fillText(dataset.data[i], model.x + x, model.y + y);
                    // Display percent in another line, line break doesn't work for fillText
                    ctx.fillText(percent, model.x + x, model.y + y + 15);
                  }
                }
              });               
            }
          }
        
        
        }
        
        var chart_$id = new Chart(ctx,{
          type:'doughnut',
          data:data,
          options:options
        })";
        $html.='</script>';



        echo $html;
    }

}